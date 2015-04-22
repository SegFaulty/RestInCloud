<?php


/**
 * Class Waps_Rest_Client
 * - use $header to send AND RECEIVE headers (status Code is added as header "Http-Code")
 *   $headers=['User-Agent' => 'Mozilla/5.0'];Waps_Rest_Client::post($apiUrl, $data, $headers, $curl);$status = $headers['Http-Code']
 * - use $curl for faster subsequent requests to the same host (no reconnect)
 *   Waps_Rest_Client::post($apiUrl, $data, $headers, $curl); $headers=[]; Waps_Rest_Client::post($apiUrl, $otherData, $headers, $curl);
 * - use $outputFileHandle to store the response content in a file
 *   $oFH = fopen('response.html', 'w+); Waps_Rest_Client::get($url, [], [], null, $oFh);fclose($oFH);
 */
class Ric_Rest_Client {

	/**
	 * @param string $url
	 * @param array $parameters
	 * @param array $headers
	 * @return array
	 */
	static public function head($url, $parameters=[], &$headers=[]){
		return self::doRequest($url, 'HEAD', $parameters, $headers);
	}

	/**
	 * @param string $url
	 * @param array $parameters
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return array
	 */
	static public function get($url, $parameters=[], &$headers=[], $outputFileHandle=null){
		return self::doRequest($url, 'GET', $parameters, $headers, $curl, $outputFileHandle);
	}

	/**
	 * post('http..', ['a'=>'b','foo'=>..]) - post array
	 * post('http..', '[jsoncontent]') - post the content
	 * post('http..', '@/home/www/..') - post the content of a file with post
	 * @param string $url
	 * @param string|array $dataStringOrArray
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return array
	 */
	static public function post($url, $dataStringOrArray=null, &$headers=[], $outputFileHandle=null){
		return self::doRequest($url, 'POST', $dataStringOrArray, $headers, $curl, $outputFileHandle);
	}

	/**
	 * @param string $url
	 * @param string|array $dataStringOrArray
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return array
	 */
	static public function put($url, $dataStringOrArray=null, &$headers=[], $outputFileHandle=null){
		return self::doRequest($url, 'PUT', $dataStringOrArray, $headers, $curl, $outputFileHandle);
	}

	/**
	 * @param string $url
	 * @param string $filePath
	 * @param array $headers
	 * @param null|resource $outputFileHandle
	 * @return array
	 */
	static public function putFile($url, $filePath, &$headers=[], $outputFileHandle=null){
		return self::doRequest($url, 'PUT-FILE', $filePath, $headers, $curl, $outputFileHandle);
	}

	/**
	 * @param string $url
	 * @param array $data
	 * @param array $headers
	 * @return array
	 */
	static public function delete($url, $data=[], &$headers=[]){
		return self::doRequest($url, 'DELETE', $data, $headers);
	}

	/**
	 *
	 * $outputFileHandle: open fileHandle (fopen('..', 'w+)
	 * $curl: you can get the curl handle (and provide it to avoid reconnects to the same host)
	 * @param string $url
	 * @param string $method
	 * @param string|array $data
	 * @param array|string $headers
	 * @param bool|null|resource $curl
	 * @param null|resource $outputFileHandle
	 * @throws RuntimeException
	 * @return array
	 */
	static public function doRequest($url, $method='GET', $data, &$headers=[], &$curl=false, $outputFileHandle=null){

		// get request headers
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
		}else{
			$ch = curl_init();
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		switch(strtoupper($method)){

			/** @noinspection PhpMissingBreakStatementInspection */
			case 'HEAD':
				curl_setopt($ch, CURLOPT_NOBODY, true); // noBody > head
			// then same as GET so fall through
			case 'GET':
				if( $data!='' ){
					// todo check if url contains a ? then use &
					$url.= '?'.$data;
				}
				break;
			case 'POST':
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // array or string or filename mit "@/home/www..."
				break;
			case 'DELETE':
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
				break;
			case 'PUT':
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
				break;
			case 'PUT-FILE':
				$filePath = $data;
				if( !file_exists($filePath) ){
					throw new RuntimeException('put file: file '.$filePath.' not found!');
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
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

		curl_setopt($ch, CURLOPT_HEADER, TRUE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

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

		$curlResponse = curl_exec($ch);
		if( $curlResponse===false ){
			throw new RuntimeException('curl request failed: '.$method.' > '.$url.' err:'.curl_errno($ch).': '.curl_error($ch));
		}
		$curlInfo = curl_getinfo($ch);
		if( $curl===false ){
			curl_close($ch);
		}else{
			$curl = $ch; // return curl handle
		}
		// headers
		$headers['Http-Code'] = $curlInfo['http_code'];
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
				$headers[$parts[0]] = $parts[1];
			}else{
				$headers[$headerString] = $headerString;
			}
		}
		return $headers;
	}

}
