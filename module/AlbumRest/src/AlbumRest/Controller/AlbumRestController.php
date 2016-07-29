<?php

namespace AlbumRest\Controller;

use Zend\Mvc\Controller\AbstractRestfulController;
use Album\Model\Album;
use Album\Form\AlbumForm;
use Album\Model\AlbumTable;
use Zend\View\Model\JsonModel;
use ZF\OAuth2\Provider\UserId\UserIdProviderInterface;
use OAuth2\Request as OAuth2Request;
use OAuth2\Response as OAuth2Response;
use InvalidArgumentException;
use OAuth2\Server as OAuth2Server;
use RuntimeException;
use Zend\Http\PhpEnvironment\Request as PhpEnvironmentRequest;
use ZF\ApiProblem\ApiProblem;
use ZF\ApiProblem\ApiProblemResponse;

class AlbumRestController extends AbstractRestfulController {
    
    protected $albumTable;
    
    /**
     * @var OAuth2Server
     */
    protected $server;

    /**
     * @var callable Factory for generating an OAuth2Server instance.
     */
    protected $serverFactory;

    /**
     * @var UserIdProviderInterface
     */
    protected $userIdProvider;

    /**
     * Constructor
     *
     * @param OAuth2Server $server
     * @param UserIdProviderInterface $userIdProvider
     */
    public function __construct($serverFactory, UserIdProviderInterface $userIdProvider)
    {
        if (! is_callable($serverFactory)) {
            throw new InvalidArgumentException(sprintf(
                'OAuth2 Server factory must be a PHP callable; received %s',
                (is_object($serverFactory) ? get_class($serverFactory) : gettype($serverFactory))
            ));
        }
        $this->serverFactory  = $serverFactory;
        $this->userIdProvider = $userIdProvider;
    }

    public function getList() {        
        $server = $this->getOAuth2Server($this->params('oauth'));

        // Handle a request for an OAuth2.0 Access Token and send the response to the client
        if (! $server->verifyResourceRequest($this->getOAuth2Request())) {
            $response   = $server->getResponse();
            return $this->getApiProblemResponse($response);
        }
        
        $results = $this->getAlbumTable()->fetchAll();
        $data = array();
        foreach ($results as $result) {
            $data[] = $result;
        }

        return new JsonModel(array("data" => $data));
    }

    public function get($id) {
        $album = $this->getAlbumTable()->getAlbum($id);
        return new JsonModel(array("data" => $album));
    }

    public function create($data) {
        $form = new AlbumForm();
        $album = new Album();
        $form->setInputFilter($album->getInputFilter());
        $form->setData($data);
        if ($form->isValid()) {
            $album->exchangeArray($form->getData());
            $id = $this->getAlbumTable()->saveAlbum($album);
        }

        return $this->get($id);
    }

    public function update($id, $data) {
        $data['id'] = $id;
        $album = $this->getAlbumTable()->getAlbum($id);
        $form = new AlbumForm();
        $form->bind($album);
        $form->setInputFilter($album->getInputFilter());
        $form->setData($data);
        if ($form->isValid()) {
            $id = $this->getAlbumTable()->saveAlbum($form->getData());
        }

        return $this->get($id);
    }

    public function delete($id) {
        $this->getAlbumTable()->deleteAlbum($id);

        return new JsonModel(array(
            'data' => 'deleted',
        ));
    }

    public function getAlbumTable() {
        if (!$this->albumTable) {
            $sm = $this->getServiceLocator();
            $this->albumTable = $sm->get('Album\Model\AlbumTable');
        }
        return $this->albumTable;
    }
    
    /**
     * Retrieve the OAuth2\Server instance.
     *
     * If not already created by the composed $serverFactory, that callable
     * is invoked with the provided $type as an argument, and the value
     * returned.
     *
     * @param string $type
     * @return OAuth2Server
     * @throws RuntimeException if the factory does not return an OAuth2Server instance.
     */
    private function getOAuth2Server($type) {
        if ($this->server instanceof OAuth2Server) {
            return $this->server;
        }

        $server = call_user_func($this->serverFactory, $type);
        if (!$server instanceof OAuth2Server) {
            throw new RuntimeException(sprintf(
                    'OAuth2\Server factory did not return a valid instance; received %s', (is_object($server) ? get_class($server) : gettype($server))
            ));
        }
        $this->server = $server;
        return $server;
    }    
    
    /**
     * Create an OAuth2 request based on the ZF2 request object
     *
     * Marshals:
     *
     * - query string
     * - body parameters, via content negotiation
     * - "server", specifically the request method and content type
     * - raw content
     * - headers
     *
     * This ensures that JSON requests providing credentials for OAuth2
     * verification/validation can be processed.
     *
     * @return OAuth2Request
     */
    protected function getOAuth2Request()
    {
        $zf2Request = $this->getRequest();
        $headers    = $zf2Request->getHeaders();

        // Marshal content type, so we can seed it into the $_SERVER array
        $contentType = '';
        if ($headers->has('Content-Type')) {
            $contentType = $headers->get('Content-Type')->getFieldValue();
        }

        // Get $_SERVER superglobal
        $server = [];
        if ($zf2Request instanceof PhpEnvironmentRequest) {
            $server = $zf2Request->getServer()->toArray();
        } elseif (!empty($_SERVER)) {
            $server = $_SERVER;
        }
        $server['REQUEST_METHOD'] = $zf2Request->getMethod();

        // Seed headers with HTTP auth information
        $headers = $headers->toArray();
        if (isset($server['PHP_AUTH_USER'])) {
            $headers['PHP_AUTH_USER'] = $server['PHP_AUTH_USER'];
        }
        if (isset($server['PHP_AUTH_PW'])) {
            $headers['PHP_AUTH_PW'] = $server['PHP_AUTH_PW'];
        }

        // Ensure the bodyParams are passed as an array
        $bodyParams = $this->bodyParams() ?: [];

        return new OAuth2Request(
            $zf2Request->getQuery()->toArray(),
            $bodyParams,
            [], // attributes
            [], // cookies
            [], // files
            $server,
            $zf2Request->getContent(),
            $headers
        );
    }
    
    /**
     * Map OAuth2Response to ApiProblemResponse
     *
     * @param OAuth2Response $response
     * @return ApiProblemResponse
     */
    protected function getApiProblemResponse(OAuth2Response $response) {
        $parameters = $response->getParameters();
        $errorUri = isset($parameters['error_uri']) ? $parameters['error_uri'] : null;
        $error = isset($parameters['error']) ? $parameters['error'] : null;
        $errorDescription = isset($parameters['error_description']) ? $parameters['error_description'] : null;

        return new ApiProblemResponse(
                new ApiProblem(
                $response->getStatusCode(), $errorDescription, $errorUri, $error
                )
        );
    }

}
