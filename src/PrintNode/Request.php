<?php

namespace PrintNode;

use BadMethodCallException;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Request
 *
 * HTTP request object.
 *
 * @method Computer[] getComputers() getComputers(int $computerId)
 * @method Printer[] getPrinters() getPrinters(int $printerId)
 * @method PrintJob[] getPrintJobs() getPrintJobs(int $printJobId)
 */
class Request
{
    /**
     * Credentials to use when communicating with API
     * @var Credentials
     */
    private $credentials;
    /**
     * API url to use with the client
     * @var string
     * */
    private $apiurl = "https://api.printnode.com";
    /**
     * Header for child authentication
     * @var string[]
     * */
    private $childauth = array();
    /**
     * Offset query argument on GET requests
     * @var int
     */
    private $offset = 0;

    /**
     * Limit query argument on GET requests
     * @var mixed
     */
    private $limit = 10;

    /**
     * Map entity names to API URLs
     * @var string[]
     */

    private $endPointUrls = array(
        'PrintNode\Client' => '/download/clients',
        'PrintNode\Download' => '/download/client',
        'PrintNode\ApiKey' => '/account/apikey',
        'PrintNode\Account' => '/account',
        'PrintNode\Tag' => '/account/tag',
        'PrintNode\Whoami' => '/whoami',
        'PrintNode\Computer' => '/computers',
        'PrintNode\Printer' => '/printers',
        'PrintNode\PrintJob' => '/printjobs',
    );

    /**
     * Map method names used by __call to entity names
     * @var string[]
     */
    private $methodNameEntityMap = array(
        'Clients' => 'PrintNode\Client',
        'Downloads' => 'PrintNode\Download',
        'ApiKeys' => 'PrintNode\ApiKey',
        'Account' => 'PrintNode\Account',
        'Tags' => 'PrintNode\Tag',
        'Whoami' => 'PrintNode\Whoami',
        'Computers' => 'PrintNode\Computer',
        'Printers' => 'PrintNode\Printer',
        'PrintJobs' => 'PrintNode\PrintJob',
    );

    /**
     * Constructor
     * @param Credentials $credentials
     * @param mixed $endPointUrls
     * @param mixed $methodNameEntityMap
     * @param int $offset
     * @param int $limit
     */
    public function __construct(Credentials $credentials, array $endPointUrls = array(), array $methodNameEntityMap = array(), $offset = 0, $limit = 10)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Function curl_init does not exist.');
        }

        $this->credentials = $credentials;

        if ($endPointUrls) {
            $this->endPointUrls = $endPointUrls;
        }

        if ($methodNameEntityMap) {
            $this->methodNameEntityMap = $methodNameEntityMap;
        }

        $this->makeEndPointUrls();

        $this->setOffset($offset);
        $this->setLimit($limit);
    }


    /**
     * Get API EndPoint URL from an entity name
     */
    private function makeEndPointUrls()
    {
        $endPointUrls = [];
        foreach ($this->methodNameEntityMap as $classes) {
            $endPointUrls[$classes] = $this->apiurl.$this->endPointUrls[$classes];
        }
        $this->endPointUrls = $endPointUrls;
    }

    /**
     * @param $entityName
     * @return mixed|string
     */
    private function getEndPointUrl($entityName): string
    {
        if (!isset($this->endPointUrls[$entityName])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Missing endPointUrl for entityName "%s"',
                    $entityName
                )
            );
        }

        return $this->endPointUrls[$entityName];
    }

    /**
     * Get entity name from __call method name
     * @param mixed $methodName
     * @return string
     */
    private function getEntityName($methodName): string
    {
        if (!preg_match('/^get(.+)$/', $methodName, $matchesArray)) {
            throw new BadMethodCallException(
                sprintf(
                    'Method %s::%s does not exist',
                    get_class($this),
                    $methodName
                )
            );
        }

        if (!isset($this->methodNameEntityMap[$matchesArray[1]])) {
            throw new BadMethodCallException(
                sprintf(
                    '%s is missing an methodNameMap entry for %s',
                    get_class($this),
                    $methodName
                )
            );
        }

        return $this->methodNameEntityMap[$matchesArray[1]];
    }

    /**
     * Initialise cURL with the options we need
     * to communicate successfully with API URL.
     * @param void
     * @return resource
     */
    private function curlInit()
    {
        $curlHandle = curl_init();

        curl_setopt($curlHandle, CURLOPT_ENCODING, 'gzip,deflate');

        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_VERBOSE, false);
        curl_setopt($curlHandle, CURLOPT_HEADER, true);

        curl_setopt($curlHandle, CURLOPT_USERPWD, (string)$this->credentials);

        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, 2);

		curl_setopt($curlHandle, CURLOPT_TIMEOUT, 4);

        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);

        return $curlHandle;
    }

    /**
     * Execute cURL request using the specified API EndPoint
     * @param mixed $curlHandle
     * @param mixed $endPointUrl
     * @return Response
     */
    private function curlExec($curlHandle, $endPointUrl): Response
    {
        curl_setopt($curlHandle, CURLOPT_URL, $endPointUrl);

        if (($response = @curl_exec($curlHandle)) === false) {
            throw new RuntimeException(
                sprintf(
                    'cURL Error (%d): %s',
                    curl_errno($curlHandle),
                    curl_error($curlHandle)
                )
            );
        }

		curl_close($curlHandle);

        $response_parts = explode("\r\n\r\n", $response);

        $content = array_pop($response_parts);

        $headers = explode("\r\n", array_pop($response_parts));

        return new Response($endPointUrl, $content, $headers);
    }

    /**
     * Make a GET request using cURL
     * @param mixed $endPointUrl
     * @return Response
     */
    private function curlGet($endPointUrl): Response
    {
        $curlHandle = $this->curlInit();
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $this->childauth);

        return $this->curlExec(
            $curlHandle,
            $endPointUrl
        );
    }

    private function curlDelete($endPointUrl): Response
    {
        $curlHandle = $this->curlInit();

        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $this->childauth);

        return $this->curlExec(
            $curlHandle,
            $endPointUrl
        );
    }

    /**
     * Make a POST/PUT/DELETE request using cURL
     *
     * @return Response
     */
    private function curlSend(): Response
    {
        $arguments = func_get_args();

        $httpMethod = array_shift($arguments);

        $data = array_shift($arguments);

        $endPointUrl = array_shift($arguments);

        $curlHandle = $this->curlInit();

        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $httpMethod);
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, (string)$data);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array_merge(array('Content-Type: application/json'), $this->childauth));

        return $this->curlExec(
            $curlHandle,
            $endPointUrl
        );
    }
    /**
     * Set the offset for GET requests
     * @param mixed $offset
     */
    public function setOffset($offset)
    {
        if (!ctype_digit($offset) && !is_int($offset)) {
            throw new InvalidArgumentException('offset should be a number');
        }

        $this->offset = $offset;
    }

    /**
     * Set the limit for GET requests
     * @param mixed $limit
     */
    public function setLimit($limit)
    {
        if (!ctype_digit($limit) && !is_int($limit)) {
            throw new InvalidArgumentException('limit should be a number');
        }

        $this->limit = $limit;
    }

    /**
     * Delete a tag for a child account
     *
     * @param string $tag
     * @return Response
     */
    public function deleteTag(string $tag): Response
    {
        $endPointUrl = $this->apiurl."/account/tag/".$tag;

        return $this->curlDelete($endPointUrl);
    }

    /**
     * Delete a child account
     * MUST have $this->childauth set to run.
     *
     * @return Response
     *
     * @throws Exception
     */
    public function deleteAccount(): Response
    {
        if (!isset($this->childauth)) {
            throw new Exception(
                sprintf(
                    'No child authentication set - cannot delete your own account.'
                )
            );
        }

        $endPointUrl = $this->apiurl."/account/";

        return $this->curlDelete($endPointUrl);
    }

    /**
     * Returns a client key.
     *
     * @param string $uuid
     * @param string $edition
     * @param string $version
     * @return Response
     */
    public function getClientKey(string $uuid, string $edition, string $version): Response
    {
        $endPointUrl = $this->apiurl."/client/key/".$uuid."?edition=".$edition."&version=".$version;

        return $this->curlGet($endPointUrl);
    }

    /**
     * Gets print job states.
     *
     * @return Entity[]
     * @throws HttpException
     */
    public function getPrintJobStates(): array
    {
        $arguments = func_get_args();

        if (count($arguments) > 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Too many arguments given to getPrintJobsStates.'
                )
            );
        }

        $endPointUrl = $this->apiurl."/printjobs/";

        if (count($arguments) == 0) {
            $endPointUrl.= 'states/';
        } else {
            $arg_1 = array_shift($arguments);
            $endPointUrl.= $arg_1.'/states/';
        }

        $response = $this->curlGet($endPointUrl);

        if ($response->getStatusCode() != '200') {
            throw new HttpException($response);
        }

        return Entity::makeFromResponse("PrintNode\State", json_decode($response->getContent()));
    }

    /**
     * Gets PrintJobs relative to a printer.
     *
     * @return Entity[]
     *
     * @throws HttpException
     */
    public function getPrintJobsByPrinters(): array
    {
        $arguments = func_get_args();

        if (count($arguments) > 2) {
            throw new InvalidArgumentException(
                sprintf(
                    'Too many arguments given to getPrintJobsByPrinters.'
                )
            );
        }

        $endPointUrl = $this->apiurl."/printers/";

        $arg_1 = array_shift($arguments);

        $endPointUrl.= $arg_1.'/printjobs/';

        foreach ($arguments as $argument) {
            $endPointUrl.= $argument;
        }

        $response = $this->curlGet($endPointUrl);

        if ($response->getStatusCode() != '200') {
            throw new HttpException($response);
        }

        return Entity::makeFromResponse("PrintNode\PrintJob", json_decode($response->getContent()));
    }

    /**
     * Get printers relative to a computer.
     *
     * @return Entity[]
     * @throws HttpException
     */
    public function getPrintersByComputers(): array
    {
        $arguments = func_get_args();

        if (count($arguments) > 2) {
            throw new InvalidArgumentException(
                sprintf(
                    'Too many arguments given to getPrintersByComputers.'
                )
            );
        }

        $endPointUrl = $this->apiurl."/computers/";

        $arg_1 = array_shift($arguments);

        $endPointUrl.= $arg_1.'/printers/';

        foreach ($arguments as $argument) {
            $endPointUrl.= $argument;
        }

        $response = $this->curlGet($endPointUrl);

        if ($response->getStatusCode() != '200') {
            throw new HttpException($response);
        }

        return Entity::makeFromResponse("PrintNode\Printer", json_decode($response->getContent()));
    }

    /**
     * Map method names getComputers, getPrinters and getPrintJobs to entities
     *
     * @param mixed $methodName
     * @param mixed $arguments
     * @return Entity[]
     * @throws HttpException
     */
    public function __call($methodName, $arguments): array
    {
        $entityName = $this->getEntityName($methodName);

        $endPointUrl = $this->getEndPointUrl($entityName);

        if (count($arguments) > 0) {
            $arguments = array_shift($arguments);

            if (!is_string($arguments)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid argument type passed to %s. Expecting a string got %s',
                        $methodName,
                        gettype($arguments)
                    )
                );
            }

            $endPointUrl = sprintf(
                '%s/%s',
                $endPointUrl,
                $arguments
            );
        } else {
            $endPointUrl = sprintf(
                '%s',
                $endPointUrl
            );
        }

        $response = $this->curlGet($endPointUrl);

        if ($response->getStatusCode() != '200') {
            throw new HttpException($response);
        }

        return Entity::makeFromResponse($entityName, json_decode($response->getContent()));
    }

    /**
     * PATCH (update) the specified entity
     * @param Entity $entity
     * @return Response
     * */
    public function patch(Entity $entity): Response
    {
        if (!($entity instanceof Entity)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid argument type passed to patch. Expecting Entity got %s',
                    gettype($entity)
                )
            );
        }

        $endPointUrl = $this->getEndPointUrl(get_class($entity));

        if (method_exists($entity, 'endPointUrlArg')) {
            $endPointUrl.= '/'.$entity->endPointUrlArg();
        }

        if (method_exists($entity, 'formatForPatch')) {
            $entity = $entity->formatForPatch();
        }


        return $this->curlSend('PATCH', $entity, $endPointUrl);
    }

    /**
     * POST (create) the specified entity
     * @param Entity $entity
     * @return Response
     */
    public function post(Entity $entity): Response
    {
        if (!($entity instanceof Entity)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid argument type passed to patch. Expecting Entity got %s',
                    gettype($entity)
                )
            );
        }

        $endPointUrl = $this->getEndPointUrl(get_class($entity));

        if (method_exists($entity, 'endPointUrlArg')) {
            $endPointUrl.= '/'.$entity->endPointUrlArg();
		}

		if (method_exists($entity, 'formatForPost')){
			$entity = $entity->formatForPost();
		}

        return $this->curlSend('POST', $entity, $endPointUrl);
    }

    /**
     * DELETE (delete) the specified entity
     * @param Entity $entity
     * @return Response
     */
    public function delete(Entity $entity): Response
    {
        $endPointUrl = $this->getEndPointUrl(get_class($entity));

        if (method_exists($entity, 'endPointUrlArg')) {
            $endPointUrl.= '/'.$entity->endPointUrlArg();
        }

        return $this->curlDelete($endPointUrl);
    }

    public function setChildAccountById($id)
    {
        $this->childauth = array("X-Child-Account-By-Id: ".$id);
    }

    public function setChildAccountByEmail($email)
    {
        $this->childauth = array("X-Child-Account-By-Email: ".$email);
    }
}
