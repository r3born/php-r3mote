<?php

namespace R3born\R3mote;

use Slim\Slim;
use JsonSchema\Validator;

/**
 * Holds the Procedure instances and makes them available through the HTTP
 * POST interface
 */
class Server {
    
    /**
     * JSON schema for single requests.
     */
    const SREQ_JSONSCHEMA = '{
        "type": "object",
        "required": [ "jsonrpc", "method" ],
        "properties": {
            "jsonrpc": { "enum": [ "2.0" ] },
            "method": { "type": "string" },
            "id": {
                "type": [ "string", "number", "null" ]
            },
            "params": {
                "type": [ "array", "object" ]
            }
        }
    }';
    
    /**
     * JSON schema for batch requests.
     */
    const MREQ_JSONSCHEMA = '[
        "type": "array",
        "items": {
            "type": "object",
            "required": [ "jsonrpc", "method" ],
            "properties": {
                "jsonrpc": { "enum": [ "2.0" ] },
                "method": { "type": "string" },
                "id": {
                    "type": [ "string", "number", "null" ]
                },
                "params": {
                    "type": [ "array", "object" ]
                }
            }
        }
    ]';
    
    /**
     * Defines if there is debug behaviour or not.
     * 
     * @var boolean
     */
    private $debug;
    
    /**
     * Slim HTTP layer.
     * 
     * @var \Slim\Slim
     */
    private $app;
    
    /**
     * Procedures held by this Server.
     * 
     * @var array
     */
    private $procedures;
    
    /**
     * JSON Schema validator instance.
     * 
     * @var \JsonSchema\Validator
     */
    private $jsonRPCValidator;
    
    /**
     * Constructor.
     *
     * Instantiation options:
     * 
     * - debug: Sets or unsets debug behaviour. Default is false.
     * - procedures: Associative array that maps method names to
     *   procedure instances (mandatory, must not be empty).
     * 
     * @param array $opts Instantiation options for this server. 
     * @throws \Exception
     */
    public function __construct($opts) {
        
        // is this a debug server?
        $this->debug = empty($opts['debug']) ? false : $opts['debug'];
        
        // instantiate the Slim app
        $this->app = new Slim([
            'debug' => $this->debug
        ]);
        
        // instantiate the JSON Schema validator
        $this->jsonRPCValidator = new Validator();
        
        // check if procedures were given
        if (empty($opts['procedures']) || !is_array($opts['procedures'])) {
            throw new \Exception('No procedures defined');
        }
        
        // prepare procedures holder, procedures must be instantiated outside
        $this->procedures = [];
        foreach ($opts['procedures'] as $name => $proc) {
            $this->procedures[$name] = $proc;
        }
    }
    
    /**
     * Start request execution.
     * 
     * This method should be called at the end of the script. It is
     * responsible for registering HTTP routes and handlers and initializing
     * the underlying Slim application.
     */
    public function run () {
        $server = $this;
    
        // register the POST handler for JSON RPC Requests
        // this handler performs a series of validations to ensure that what arrives
        // at the Procedure is exactly what the procedure expects
        $this->app->post('/r3mote', function () use ($server) {
            
            // when there is an error, the function returns early,
            // and the "result" field of render() is used as debug info
            // procedures can use this facility by setting the error and returning
            // whatever debug info they wish, which will only be included in
            // debug mode in a non standard "debug" field (see render() doc)
            
            // check if content type is application/json
            if (strpos(
                $server->app->request()->headers('Content-Type'),
                'application/json'
            ) !== 0) {
                echo $server->render(null, Procedure::EREQST, 'Content-Type is not application/json.');
                return;
            }
            
            // check if json is well formed
            $request = json_decode($server->app->request()->getBody());
            if (is_null($request)) {
                echo $server->render(null, Procedure::EREQST, 'Content is not decodeable as JSON.');
                return;
            }
            
            // check if it is a valid json rpc request    
            // a json-rpc request is either an array or an object
            
            if (is_object($request)) {
                
                // validate against the schema
                if (!$server->check($request, Server::SREQ_JSONSCHEMA)) {
                    echo $server->render(null, Procedure::EREQST, 'Content is not a valid JSON RPC 2.0 request');
                    return;
                }
                
                // give id and parameters sensible defaults, as per the JSON schema spec
                $id     = empty($request->id)     ? null : $request->id;
                $params = empty($request->params) ? null : $request->params;
                
                // execute the procedure
                $result = $server->executeProcedure(
                        [], // empty context TODO: implement context support
                        $request->method,
                        $params,
                        $error
                );

                // render the response
                if ($error) {
                    echo $server->render($id, $error, $result);
                } else {
                    echo $server->render($id, null, $result);
                }

                return;
            }
            
            if (is_array($request)) {
                
                // so many of them, woo :)
                $requests = $request;
                
                // validate against the schema
                if (!$server->check($requests, Server::MREQ_JSONSCHEMA)) {
                    echo $server->render(null, Procedure::EREQST, 'Content is not a valid JSON RPC 2.0 batch request');
                    return;
                }
                
                // prepare a placeholder
                $results = [];
                
                // respond to each request
                foreach ($requests as $request) {
                    
                    // give id and parameters sensible defaults, as per the JSON schema spec
                    $id     = empty($request->id)     ? null : $request->id;
                    $params = empty($request->params) ? null : $request->params;

                    // execute the procedure
                    $result = $server->executeProcedure(
                            [], // empty context TODO: implement context support
                            $request->method,
                            $params,
                            $error
                    );
                    
                    // render the response
                    if ($error) {
                        $results[] = $server->render($id, $error, $result);
                    } else {
                        $results[] = $server->render($id, null, $result);
                    }
                }
                
                // render the final response
                echo '[', implode(',', $results), ']';
                return;
            }
            
            // neither object nor array, it's a malformed request
            echo $server->render(null, Procedure::EREQST, 'Content is not a valid JSON RPC 2.0 request');
        });
        
        $this->app->run();
    }
    
    /**
     * Helper to make JSON validation slightly easier.
     * 
     * Both the value and schema must be valid json values, as if
     * returned by json_decode().
     * 
     * @param \stdClass $value  Object to validate.
     * @param \stdClass $schema Schema to validate against.
     * @return boolean If this value validates againts the schema.
     */
    private function check($value, $schema) {
        $this->jsonRPCValidator->reset();
        $this->jsonRPCValidator->check($value, $schema);
        
        return $this->jsonRPCValidator->isValid();
    }
    
    /**
     * Helper to render the JSON RPC response.
     * 
     * This method includes functionality to output debug information in case of
     * an error when in debug mode. Anything passed as result will be output in
     * the "debug" field if error isn't empty. 
     * 
     * @param mixed $id
     * @param mixed $error
     * @param mixed $result
     * @return string
     */
    private function render ($id, $error, $result) {
        $this->app->response()->header('Content-Type', 'application/json');
        
        $output = new \stdClass;
        $output->id = $id;
        
        if ($error) {
            $output->error = $error;
            
            if ($this->debug) {
                $output->debug = $result;
            }
        } else {
            $output->result = $result;
        }
        
        $rendered = json_encode($output);
        
        if ($this->debug) {
            $this->app->response()->header(
                'X-R3mote-Debug-Microtime', 
                microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            );
        }
        
        return $rendered;
    }
    
    /**
     * Execute a procedure by name with a given context and parameters.
     * 
     * This method also checks for the method's existence, setting the appropriate error
     * if it does not exist, and validates the parameters against the Procedure
     * provided schema.
     *  
     * @param mixed  $context    TO DEFINE LATER.
     * @param string $procedure  Procedure name.
     * @param mixed  $parameters Parameters to call the procedure with.  
     * @param string $error      Error in case of failure.
     * 
     * @return mixed Procedure return value on success, debug info on failure.
     */
    private function executeProcedure ($context, $procedure, $parameters, &$error = null) {
        
        $result = null;
        
        // check if procedure exists
        if (!isset($this->procedures[$procedure])) {
            
            $error  = Procedure::EREQST;
            
            if ($this->debug) {
                $result = 'Method "' . $procedure . '" does not exist.';
            }
                
            return $result;
        } 
        
        $p = $this->procedures[$procedure];
        
        // check parameters are valid
        if (!$this->check($parameters, $p->parameters())) {
            
            $error = Procedure::EPARAM;
            
            if ($this->debug) {
                $result = "Invalid, missing or unsupported parameter.";
            }
            
            return $result;
        }
        
        // execute procedure
        $result = $p->execute($context, $parameters, $error);
        
        // perform aditional validations when in debug mode
        if ($this->debug) {
            
            $stdErrors = [
                Procedure::EINTRN,
                Procedure::EREQST,
                Procedure::EPARAM,
                Procedure::EERROR,
                Procedure::ERESLT
            ];
            
            if ($error) {
                
                if (array_search($error, $p->errors(), true) === false &&
                    array_search($error, $stdErrors, true) === false) {
                    
                    // if the procedure returns an unlisted non-standard error,
                    // spit out a debug alert
                    
                    $result = [
                        'expected' => $p->errors(),
                        'returned' => $error
                    ];
                    $error = Procedure::EERROR;
                }
                
            } else {
                
                if (!$this->check($result, $p->result())) {
                    
                    // if the procedure returns something unexpected (i. e.,
                    // violates it's own contract) alert the developer
                    
                    $error  = Procedure::ERESLT;
                    $result = [
                        'expected' => $p->result(),
                        'returned' => $result
                    ];
                }
                
            }
            
        }
        
        return $result;
    }
}