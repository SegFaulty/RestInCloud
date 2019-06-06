<?php


/**
 * Class Ric_Rest_Client
 * - use $header to send AND RECEIVE headers (status Code is added as header "Http-Code")
 *   $headers=['User-Agent' => 'Mozilla/5.0'];Ric_Rest_Client::post($apiUrl, $data, $headers, $curl);$status = $headers['Http-Code']
 * - internal persistent connection handling:
 *   curl hast a connection pooling feature implemented, it will reuse an open connection
 *   default: ($curl=true on doRequest): the curlHandle will be stored as staticAttribute and reused
 *   this means if the server supports keepalive and you call the same target-ip and port the connection can be reused (+40% speed)
 *   a curl-handle will cache 5 connections (default CURLOPT_MAXCONNECTS)
 *   if the internal connectionHandle is used ($curl===true), it well be reseted first
 * - use $curl for faster subsequent requests to the same host (no reconnect)
 * - use $outputFileHandle to store the response content in a file
 *   $oFH = fopen('response.html', 'w+); Ric_Rest_Client::get($url, [], [], null, $oFh);fclose($oFH);
 * - request (and transparently decodes) with Accept-Encoding => deflate, gzip (if available, curl automatic)
 * - default timeout is 60s change with Ric_Rest_Client::setDefaultCurlOption(CURLOPT_TIMEOUT,null)
 */
class Ric_Rest_Client {

	const HEADER__HTTP_CODE = 'Http-Code';
	static protected $curlHandle = null;

	/**
	 * @var array
	 */
	static protected $defaultCurlOptions = array(
			CURLOPT_CONNECTTIMEOUT       => 10,
			CURLOPT_TIMEOUT              => 60,
			CURLOPT_FOLLOWLOCATION       => true,
			CURLOPT_MAXREDIRS            => 10,
			CURLOPT_DNS_USE_GLOBAL_CACHE => true,
			CURLOPT_DNS_CACHE_TIMEOUT    => 600,
			CURLOPT_FTP_USE_EPSV         => false, // don't use extended passive mode -> better compatibility
			CURLOPT_SSL_VERIFYPEER       => true, // verify the peer's SSL certificate
	);

	/**
	 * return single or all defaultOptions
	 * @param string $curlOpt // CURLOPT_*
	 * @return mixed|null
	 */
	static public function getDefaultCurlOptions($curlOpt = null){
		if( $curlOpt===null ){
			return self::$defaultCurlOptions;
		}
		if( isset(self::$defaultCurlOptions[$curlOpt]) ){
			return self::$defaultCurlOptions[$curlOpt];
		}
		return null;
	}

	/**
	 * set (or if value===null remove) defaultOption
	 * @param string $curlOpt // CURLOPT_*
	 * @param mixed $value
	 */
	static public function setDefaultCurlOption($curlOpt, $value){
		if( $value===null ){
			unset(self::$defaultCurlOptions[$curlOpt]);
		}else{
			self::$defaultCurlOptions[$curlOpt] = $value;
		}
	}

	/**
	 * @param string $url
	 * @param array $parameters
	 * @param array $headers
	 * @return string
	 */
	static public function head($url, $parameters = [], &$headers = [], $curlOptions = []){
		$curl = true;
		return self::doRequest($url, 'HEAD', $parameters, $headers, $curl, null, $curlOptions);
	}

	/**
	 * @param string $url
	 * @param array $parameters
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return string
	 */
	static public function get($url, $parameters = [], &$headers = [], $outputFileHandle = null, $curlOptions = []){
		$curl = true;
		return self::doRequest($url, 'GET', $parameters, $headers, $curl, $outputFileHandle, $curlOptions);
	}

	/**
	 * post('http..', ['a'=>'b','foo'=>..]) - post array
	 * post('http..', '[jsoncontent]') - post the content
	 * post('http..', '@/home/www/..') - post the content of a file with post
	 *
	 * do you need BasicAuth? use this $headers['Authorization'] = 'Basic '.base64_encode($user.':'.$password);
	 *
	 * @param string $url
	 * @param string|array $dataStringOrArray
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return string
	 */
	static public function post($url, $dataStringOrArray = null, &$headers = [], $outputFileHandle = null, $curlOptions = []){
		$curl = true;
		return self::doRequest($url, 'POST', $dataStringOrArray, $headers, $curl, $outputFileHandle, $curlOptions);
	}

	/**
	 * @param string $url
	 * @param string|array $dataStringOrArray
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return string
	 */
	static public function put($url, $dataStringOrArray = null, &$headers = [], $outputFileHandle = null, $curlOptions = []){
		$curl = true;
		return self::doRequest($url, 'PUT', $dataStringOrArray, $headers, $curl, $outputFileHandle, $curlOptions);
	}

	/**
	 * @param string $url
	 * @param string $filePath
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return string
	 */
	static public function putFile($url, $filePath, &$headers = [], $outputFileHandle = null, $curlOptions = []){
		$curl = true;
		return self::doRequest($url, 'PUT-FILE', $filePath, $headers, $curl, $outputFileHandle, $curlOptions);
	}

	/**
	 * @param string $url
	 * @param array $data
	 * @param array $headers
	 * @return string
	 */
	static public function delete($url, $data = [], &$headers = [], $curlOptions = []){
		$curl = true;
		return self::doRequest($url, 'DELETE', $data, $headers, $curl, null, $curlOptions);
	}

	/**
	 * $curl: you can get the curl handle (and provide it to avoid reconnects to the same host)
	 * $curl===true (default) : internal persistent connection handling - the curlHandle will be internally reused (static attribute), (the used curl handle is regardless returned)
	 * $curl===false : a curl-handle is open an closed for every request
	 * $outputFileHandle: open fileHandle (fopen('..', 'w+)
	 *
	 * @param string $url
	 * @param string $method
	 * @param string|array $data
	 * @param array|string $headers
	 * @param bool|null|resource $curl
	 * @param null|resource $outputFileHandle
	 * @param array $curlOptions
	 * @return string
	 * @throws Ric_Rest_CurlException
	 * @throws RuntimeException
	 */
	static public function doRequest($url, $method = 'GET', $data = '', &$headers = [], &$curl = true, $outputFileHandle = null, $curlOptions = []){

		// get request headers
		if( isset($headers[self::HEADER__HTTP_CODE]) ){
			throw new RuntimeException(self::HEADER__HTTP_CODE.'- Header as requestHeader detected - i think you gave me the last response headers - thats a common mistake, please reset headers before reuse the variable ;-) ');
		}
		$requestHeaders = [];
		$headers = (array) $headers; // cast explizit, damit es es null uebergeben werden kann
		foreach( $headers as $key=>$header ){
			if( !is_int($key) ){
				$header = $key.': '.$header;
			}
			$requestHeaders[] = $header;
		}
		$requestHeaders[] = 'Expect:';  // suppress code 100 request -> response  some infos https://support.urbanairship.com/entries/59909909--Expect-100-Continue-Issues-and-Risks
		$headers = []; // clear for response headers

		if( is_array($data) ){
			$data = http_build_query($data);
		}

		if( is_resource($curl) ){
			$ch = $curl;
		}elseif( $curl===true AND PHP_VERSION_ID>=50500 ){ // curl_reset (PHP 5 >= 5.5.0, PHP 7)
			// internal persistent connection handling
			if( !is_resource(self::$curlHandle) ){
				self::$curlHandle = curl_init();
			}
			$ch = self::$curlHandle;
			curl_reset($ch); // reset to default options (only here in the generic path, if the curl handle is provide by user we not reset before or after operation (only custom-request)
		}else{
			$ch = curl_init();
		}

		// reset some option, for reuse curl (CURLOPT_CUSTOMREQUEST will reset before returning curl handle)
		curl_setopt($ch, CURLOPT_HTTPGET, true);

		$customRequest = false;
		switch(strtoupper($method)){

			/** @noinspection PhpMissingBreakStatementInspection */
			case 'HEAD':
				curl_setopt($ch, CURLOPT_NOBODY, true); // noBody > head
			// then same as GET so fall through
			case 'GET':
				if( $data!='' ){
					$url.= ( strstr($url,'?') ? '&' : '?' ).$data; // ?data or &data if ? already in use
				}
				break;
			case 'POST':
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // array or string or filename mit "@/home/www..."
				break;
			case 'DELETE':
				$customRequest = true;
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
				break;
			case 'PUT':
				$customRequest = true;
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
				break;
			case 'PUT-FILE':
				$filePath = $data;
				if( !is_file($filePath) ){
					throw new RuntimeException('put file: file '.$filePath.' not found or not a regular file');
				}
				$fh = fopen($filePath, 'rb');
				curl_setopt($ch, CURLOPT_PUT, true);
				curl_setopt($ch, CURLOPT_INFILE, $fh);
				curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
				break;
			default:
				throw new RuntimeException('unknown method: '.$method);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

		$headerFileHandle = fopen('php://memory', 'w+');
		if( $outputFileHandle!==null ){
			$contentFileHandle = $outputFileHandle;
		}else{
			$contentFileHandle = fopen('php://memory', 'w+');
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
		curl_setopt($ch, CURLOPT_FILE, $contentFileHandle);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_WRITEHEADER, $headerFileHandle);

		$curlOptions = $curlOptions + self::$defaultCurlOptions;
		if( PHP_VERSION_ID>=50500 ){
			// add response compression, transparent curl magic: gzip deflate
			$curlOptions = $curlOptions + [CURLOPT_ACCEPT_ENCODING => '']; // If an empty string, "", is set, a header containing all supported encoding types is sent.

		}
		curl_setopt_array($ch, $curlOptions);

		$curlResponse = curl_exec($ch);
		if( $curlResponse===false ){
			throw new Ric_Rest_CurlException('curl request failed: '.$method.' > '.$url.' err:'.curl_errno($ch).': '.curl_error($ch), curl_errno($ch));
		}
		$curlInfo = curl_getinfo($ch);
		if( $curl===false ){
			curl_close($ch);
		}else{
			// reset custom-request
			if( $customRequest ){
				if( PHP_VERSION_ID<50600 ){
					// in php < 5.6 kann CURLOPT_CUSTOMREQUEST nicht reseted werden, daher muessen wir hier schließen
					// php bug http://stackoverflow.com/questions/4163865/how-to-reset-curlopt-customrequest)
					curl_close($ch);
				}else{
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null); // reset
				}
			}
			$curl = $ch; // return curl handle
		}
		// headers
		$headers[self::HEADER__HTTP_CODE] = $curlInfo['http_code'];
		rewind($headerFileHandle);
		$headersString = fread($headerFileHandle, 10000000);
		fclose($headerFileHandle);
		$headers+= self::parseHeaders(trim($headersString));
		// content
		rewind($contentFileHandle);
		$content = '';
		if( $outputFileHandle===null ){
			while (!feof($contentFileHandle)) {
				$content.= fread($contentFileHandle, 81920);
			}
			fclose($contentFileHandle);
		}
		return $content;
	}

	/**
	 * usage:
	 *    $contentType =  Ric_Rest_Client::getHeader('content-type', $headers);
	 *
	 * @param string $header case insensitive !!
	 * @param null $default
	 * @return string|null
	 */
	static public function getHeader($header, $headers, $default = null){
		$result = $default;
		$lowerName = strtolower($header);
		foreach( $headers as $name => $value ){
			if( strtolower($name)==$lowerName ){
				$result = $value;
			}
		}
		return $result;
	}


	/**
	 * @param string $headerContent
	 * @return array
	 */
	static protected function parseHeaders($headerContent){
		$headerStrings = explode("\r\n", $headerContent);
		$headers = [];
		foreach( $headerStrings as $headerString ){
			if( $headerString=='' ){
				$headers = []; // reset all header, ist a new header block because of redirects!
				continue;
			}
			$parts = explode(':', $headerString, 2);
			if( count($parts)==2 ){
				$headers[trim($parts[0])] = trim($parts[1]);
			}else{
				$headers[$headerString] = $headerString;
			}
		}
		return $headers;
	}

}

class Ric_Rest_CurlException extends RuntimeException {

}