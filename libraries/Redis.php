<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * CodeIgniter Redis
 *
 * A CodeIgniter library to interact with Redis
 *
 * @package        	CodeIgniter
 * @category    	Libraries
 * @author        	Joël Cox
 * @version			v0.4
 * @link 			https://github.com/joelcox/codeigniter-redis
 * @link			http://joelcox.nl
 * @license         http://www.opensource.org/licenses/mit-license.html
 */
class CI_Redis {

	/**
	 * CI
	 *
	 * CodeIgniter instance
	 * @var 	object
	 */
	private $_ci;

	/**
	 * Connection
	 *
	 * Socket handle to the Redis server
	 * @var		handle
	 */
	private $_connection;

	/**
	 * Debug
	 *
	 * Whether we're in debug mode
	 * @var		bool
	 */
	public $debug = FALSE;

	/**
	 * CRLF
	 *
	 * User to delimiter arguments in the Redis unified request protocol
	 * @var		string
	 */
	const CRLF = "\r\n";

	/**
	 * Constructor
	 */
	public function __construct($params = array())
	{

		log_message('debug', 'Redis Class Initialized');

		$this->_ci = get_instance();
		$this->_ci->load->config('redis');

		// Check for the different styles of configs
		if (isset($params['connection_group']))
		{
			// Specific connection group
			$config = $this->_ci->config->item('redis_' . $params['connection_group']);
		}
		elseif (is_array($this->_ci->config->item('redis_default')))
		{
			// Default connection group
			$config = $this->_ci->config->item('redis_default');
		}
		else
		{
			// Original config style
			$config = array(
				'host' => $this->_ci->config->item('redis_host'),
				'port' => $this->_ci->config->item('redis_port'),
				'password' => $this->_ci->config->item('redis_password'),
			);
		}

		// Connect to Redis
		$this->_connection = @fsockopen($config['host'], $config['port'], $errno, $errstr, 3);

		// Display an error message if connection failed
		if ( ! $this->_connection)
		{
			show_error('Could not connect to Redis at ' . $config['host'] . ':' . $config['port']);
		}

		// Authenticate when needed
		$this->_auth($config['password']);

	}

	/**
	 * Call
	 *
	 * Catches all undefined methods
	 * @param	string	method that was called
	 * @param	mixed	arguments that were passed
	 * @return 	mixed
	 */
	public function __call($method, $arguments)
	{
		$request = $this->_encode_request($method, $arguments);
		return $this->_write_request($request);
	}

	/**
	 * Command
	 *
	 * Generic command function, just like redis-cli
	 * @param	string	full command as a string
	 * @return 	mixed
	 */
	public function command($string)
	{

		$slices = explode(' ', $string);
		$request = $this->_encode_request($slices[0], array_slice($slices, 1));

		return $this->_write_request($request);
	}

	/**
	 * Auth
	 *
	 * Runs the AUTH command when password is set
	 * @param 	string	password for the Redis server
	 * @return 	void
	 */
	private function _auth($password = NULL)
	{

		// Authenticate when password is set
		if ( ! empty($password))
		{

			// See if we authenticated successfully
			if ($this->command('AUTH ' . $password) !== 'OK')
			{
				show_error('Could not connect to Redis, invalid password');
			}

		}

	}

	/**
	 * Clear Socket
	 *
	 * Emptires the socket buffer of _connection so data does not bleed
	 * @return 	NULL
	 */
	public function _clear_socket() {
		// flush everything left in _connection
		fflush ( $this->_connection );

		return NULL;
	}


	/**
	 * Write request
	 *
	 * Write the formatted request to the socket
	 * @param	string 	request to be written
	 * @return 	mixed
	 */

	private function _write_request($request)
	{

		if ($this->debug === TRUE)
		{
			log_message('debug', 'Redis unified request: ' . $request);
		}

		// how long is the data being sent?
		$value_length = strlen($request);

		// if there isnt any data, just return
		if ($value_length <= 0) {
			return NULL;
		}

		// Handle reply if data is less than or equal to 8192 bytes, just send it over
		if ( $value_length <= 8192 ) {

			fwrite($this->_connection, $request);
		
		// If data is greater than 8192 bytes, chunk it in 8192 byte chunks	
		} else {
			while ( $value_length > 0 ) { 

				// if we have more than 8192, only take what we can handle
				if ( $value_length > 8192 ) {
					$sendSize = 8192;
				}

				// send our chunk
				fwrite($this->_connection, $request, $sendSize);

				// how much is left to send?
				$value_length = $value_length - $sendSize;

				// remove data sent from outgoing data
				$request = substr($request, $sendSize, $value_length);
			
			} // end while value_length is greater than 0

		} // end else (value length is greater than 8192)

		// read our request into a variable
		$return = $this->_read_request();

		// trim errant endings from returned data
		$return = rtrim($return);

		

		// clear the socket so no data remains in the buffer
		$this->_clear_socket();

		return $return;

	}



	/**
	 * Read request
	 *
	 * Route each response to the appropriate interpreter
	 * @return 	mixed
	 */
	private function _read_request()
	{

		$type = fgetc($this->_connection);

		// set how many times we will attempt to trash bad data in search of a valid type indicator
		$typeErrorLimit = 50;
		// init try counter for error limit search
		$try = 0;
		while ( $type != "+" && $type != "-" && $type != ":" && $type != "$" && $type != "*" && $try < $typeErrorLimit ) {
			$type = fgetc($this->_connection);
			$try++;
		}

		if ($this->debug === TRUE)
		{
			log_message('debug', 'Redis response type: ' . $type);
		}

		switch ($type)
		{
			case '+':
				return $this->_single_line_reply();
				break;
			case '-':
				return $this->_error_reply();
				break;
			case ':':
				return $this->_integer_reply();
				break;
			case '$':
				return $this->_bulk_reply();
				break;
			case '*':
				return $this->_multi_bulk_reply();
				break;
			default:
				return FALSE;
		}

	}

	/**
	 * Single line reply
	 *
	 * Reads the reply before the EOF
	 * @return 	mixed
	 */
	private function _single_line_reply()
	{
		$value = rtrim(fgets($this->_connection));
		$this->_clear_socket();
		return $value;
	}

	/**
	 * Error reply
	 *
	 * Write error to log and return false
	 * @return 	bool
	 */
	private function _error_reply()
	{
		// Extract the error message
		$error = substr(fgets(($this->_connection), 4));
		log_message('error', 'Redis server returned an error: ' . $error);
        $this->_clear_socket();

		return FALSE;
	}

	/**
	 * Integer reply
	 *
	 * Returns an integer reply
	 * @return 	int
	 */
	private function _integer_reply()
	{
		return (int) rtrim(fgets($this->_connection));
	}

    /**
     * Bulk reply
     *
     * Reads to amount of bits to be read and returns value within
     * the pointer and the ending delimiter
     * @return  string
     */
    private function _bulk_reply()
    {
	// how long is the data we are reading?  Support waiting for data to fully return from redis and enter into socket.  
        $value_length = (int) fgets($this->_connection);

	// return null if length of connection is null
        if ($value_length <= 0) return NULL;

	// prevent read and response variables from being re-used
        $response = '';

	// Handle reply if data is less than or equal to 8192 bytes, just read it
	if ( $value_length <= 8192 ) {

		$response = fread($this->_connection, $value_length);
	
	// If reply is greater than 8192 bytes, read it in 8192 byte chunks	
	} else {
		// handle large replies
		// setup a diminishing variable for how much data we have to read
		$dataLeft = $value_length;

		// if the data left is greater than 0, keep reading
        	while ( $dataLeft > 0 ) { 

			// if we have more than 8192, only take what we can handle
			if ( $dataLeft > 8192 ) {
				$readSize = 8192;
			} else {
				$readSize = $dataLeft;
			}

			// read our chunk
			$chunk = fread($this->_connection, $readSize );

			// support reading very long responses that don't come through with one fread
			$chunkLen = strlen( $chunk );
			while ( $chunkLen < $readSize ) {
				$keepReading = $readSize - $chunkLen;
				$chunk .= fread($this->_connection, $keepReading );
				$chunkLen = strlen( $chunk );
			}
			$response .= $chunk;

			// re-calculate how much data is left to read
			$dataLeft = $dataLeft - $readSize;
		
		} // end while value_length is greater than 0

	} // end else (value length is greather than 8192)




	// clear the socket in case anything remains in there
	$this->_clear_socket();

	return isset($response) ? $response : FALSE;
    }

	/**
	 * Multi bulk reply
	 *
	 * Reads n bulk replies and return them as an array
	 * @return 	array
	 */
	private function _multi_bulk_reply()
	{
		// Get the amount of values in the response
		$total_values = (int) fgets($this->_connection);

		// Loop all values and add them to the response array
		for ($i = 0; $i < $total_values; $i++)
		{
			// Remove the new line and carriage return before reading
			// another bulk reply
			fgets($this->_connection, 2);
			$response[] = $this->_bulk_reply();
		}

		// clear the socket
		$this->_clear_socket();

		return isset($response) ? $response : FALSE;
	}

	/**
	 * Encode request
	 *
	 * Encode plain-text request to Redis protocol format
	 * @link 	http://redis.io/topics/protocol
	 * @param 	string 	request in plain-text
	 * @param   string  additional data (string or array, depending on the request)
	 * @return 	string 	encoded according to Redis protocol
	 */
	private function _encode_request($method, $arguments = array())
	{
		$argument_count = $this->_count_arguments($arguments);

		// Set the argument count and prepend the method
		$request = '*' . $argument_count . self::CRLF;
		$request .= '$' . strlen($method) . self::CRLF . $method . self::CRLF;

		if ($argument_count === 1) return $request;

		// Append all the arguments in the request string
		foreach ($arguments as $argument)
		{

			if (is_array($argument))
			{
				$is_associative_array = self::is_associative_array($argument);

				foreach ($argument as $key => $value)
				{
					// Prepend the key if we're dealing with a hash
					if ($is_associative_array)
					{
						$request .= '$' . strlen($key) . self::CRLF . $key . self::CRLF;
					}

					$request .= '$' . strlen($value) . self::CRLF . $value . self::CRLF;
				}
			}
			else
			{
				$request .= '$' . strlen($argument) . self::CRLF . $argument . self::CRLF;
			}

		}

		return $request;
	}

	/**
	 * Count arguments
	 *
	 * Count the amount of arguments we need to pass to Redis while taking
	 * into consideration lists, hashes and strings
	 */
	private function _count_arguments($arguments)
	{
		$argument_count = 1;

		// Count how many arguments we need to push over the wire
		foreach ($arguments as $argument)
		{

			// We're dealing with 2n arguments if we're consider the
			// keys as arguments too.
			if (is_array($argument) AND self::is_associative_array($argument))
			{
				$argument_count += (count($argument) * 2);
			}
			elseif (is_array($argument))
			{
				$argument_count += count($argument);
			}
			else
			{
				$argument_count++;
			}

		}

		return $argument_count;

	}

	/**
	 * Info
	 *
	 * Overrides the default Redis response, so we can return a nice array
	 * of the server info instead of a nasty string.
	 * @return 	array
	 */
	public function info($section = FALSE)
	{
		if ($section !== FALSE)
		{
			$response = $this->command('INFO '. $section);
		}
		else
		{
			$response = $this->command('INFO');
		}

		$data = array();
		$lines = explode(self::CRLF, $response);

		// Extract the key and value
		foreach ($lines as $line)
		{
			$parts = explode(':', $line);
			if (isset($parts[1])) $data[$parts[0]] = $parts[1];
		}

		return $data;
	}

	/**
	 * Debug
	 *
	 * Set debug mode
	 * @param	bool 	set the debug mode on or off
	 * @return 	void
	 */
	public function debug($bool)
	{
		$this->debug = (bool) $bool;
	}

	/**
	 * Destructor
	 *
	 * Kill the connection
	 * @return 	void
	 */
	function __destruct()
	{
		if ($this->_connection) fclose($this->_connection);
	}

	/**
	 * Is associative array
	 *
	 * Checkes whether the array has only intergers as key, starting at
	 * index 0, untill the array length - 1.
	 * @param 	array 	the array to be checked
	 * @return 	bool
	 */
	public static function is_associative_array($array)
	{
		$keys = array_keys($array);

		if (min($keys) === 0 AND max($keys) === count($array) - 1) return FALSE;
		return TRUE;
	}

}
