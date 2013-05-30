<?php

namespace R3born\R3mote;

/**
 * Base interface for a remote procedure declaration
 */
interface Procedure {
    
    /**
     * Default error code for internal errors.
     */
    const EINTRN = 'EINTRN';
    
    /**
     * Default error code for missing/invalid parameter errors.
     */
    const EPARAM = 'EPARAM';
    
    /**
     * Default error code for malformed requests.
     */
    const EREQST = 'EREQST';
    
    /**
     * Default error code for malformed results (debug only).
     */
    const ERESLT = 'ERESLT';
    
    /**
     * Default error code for undeclared errors (debug only).
     */
    const EERROR = 'EERROR';
    
    /**
     * Declares the RPC description.
     * 
     * @return string RPC description.
     */
    public function description ();
    
    /**
     * Declares the RPC parameters.
     * 
     * @return \stdClass An object describing a json schema for the parameters object/array.
     */
    public function parameters ();
    
    /**
     * Declares the RPC errors.
     * 
     * @return array A list of error codes this procedure is allowed to return.
     */
    public function errors ();
    
    /**
     * Declares the RPC result.
     * 
     * @return \stdClass An object describing a json schema for the result.
     */
    public function result ();
    
    /**
     * Execute procedure
     * 
     * @param array        $context    Current context for request execution.
     * @param array|object $parameters Request parameters.
     * @param string       $error      Error code or null if there is no error.
     * 
     * @return mixed Procedure result or null if there is an error.
     */
    public function execute ($context, $parameters, &$error = null);
}