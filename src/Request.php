<?php

namespace InstagramAPI;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\ResponseInterface as HttpResponseInterface;
use Psr\Http\Message\StreamInterface;
use function GuzzleHttp\Psr7\stream_for;

/**
 * Bridge between Instagram Client calls, the object mapper & response objects.
 */
class Request
{
    /**
     * The Instagram class instance we belong to.
     *
     * @var \InstagramAPI\Instagram
     */
    protected $_parent;

    /**
     * Which API version to use for this request.
     *
     * @var int
     */
    protected $_apiVersion;

    /**
     * Endpoint to request.
     *
     * @var string
     */
    protected $_url;

    /**
     * An array of query params.
     *
     * @var array
     */
    protected $_params;

    /**
     * An array of POST params.
     *
     * @var array
     */
    protected $_posts;

    /**
     * Raw request body.
     *
     * @var StreamInterface
     */
    protected $_body;

    /**
     * An array of files (data) to upload.
     *
     * @var array
     */
    protected $_files;

    /**
     * An array of HTTP headers.
     *
     * @var string[]
     */
    protected $_headers;

    /**
     * Whether this API call needs authorization.
     *
     * On by default since most calls require authorization.
     *
     * @var bool
     */
    protected $_needsAuth;

    /**
     * Whether this API call needs signing POST data.
     *
     * On by default since most calls require it.
     *
     * @var bool
     */
    protected $_signedPost;

    /**
     * Cached HTTP response object.
     *
     * @var HttpResponseInterface
     */
    protected $_httpResponse;

    /**
     * Opened file handles.
     *
     * @var resource[]
     */
    protected $_handles;

    /**
     * Whether to append default headers.
     *
     * @var bool
     */
    protected $_defaultHeaders;

    /**
     * Constructor.
     *
     * @param Instagram $parent
     * @param string    $url
     */
    public function __construct(
        \InstagramAPI\Instagram $parent,
        $url)
    {
        $this->_parent = $parent;
        $this->_url = $url;

        // Set defaults.
        $this->_apiVersion = 1;
        $this->_headers = [];
        $this->_params = [];
        $this->_posts = [];
        $this->_files = [];
        $this->_needsAuth = true;
        $this->_signedPost = true;
        $this->_defaultHeaders = true;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        // Ensure that all opened handles are closed.
        $this->_closeHandles();
    }

    /**
     * Set API version to use.
     *
     * @param int $apiVersion
     *
     * @throws \InvalidArgumentException In case of unsupported API version.
     *
     * @return self
     */
    public function setVersion(
        $apiVersion)
    {
        if (!isset(Constants::API_URLS[$apiVersion])) {
            throw new \InvalidArgumentException(sprintf('"%d" is not a supported API version.', $apiVersion));
        }
        $this->_apiVersion = $apiVersion;

        return $this;
    }

    /**
     * Add query param to request, overwriting any previous value.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    public function addParam(
        $key,
        $value)
    {
        if ($value === true) {
            $value = 'true';
        } elseif ($value === false) {
            $value = 'false';
        }
        $this->_params[$key] = $value;

        return $this;
    }

    /**
     * Add POST param to request, overwriting any previous value.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    public function addPost(
        $key,
        $value)
    {
        $this->_posts[$key] = $value;

        return $this;
    }

    /**
     * Add an on-disk file to a POST request, which causes this to become a multipart form request.
     *
     * @param string      $key      Form field name.
     * @param string      $filepath Path to a file.
     * @param string|null $filename Filename to use in Content-Disposition header.
     * @param array       $headers  An associative array of headers.
     *
     * @throws \InvalidArgumentException
     *
     * @return self
     */
    public function addFile(
        $key,
        $filepath,
        $filename = null,
        $headers = [])
    {
        // Validate
        if (!is_file($filepath)) {
            throw new \InvalidArgumentException(sprintf('File "%s" does not exist.', $filepath));
        }
        if (!is_readable($filepath)) {
            throw new \InvalidArgumentException(sprintf('File "%s" is not readable.', $filepath));
        }
        // Inherit value from $filepath, if not supplied.
        if ($filename === null) {
            $filename = $filepath;
        }
        $filename = basename($filename);
        // Default headers.
        $headers = $headers + [
            'Content-Type'              => 'application/octet-stream',
            'Content-Transfer-Encoding' => 'binary',
        ];
        $this->_files[$key] = [
            'filepath' => $filepath,
            'filename' => $filename,
            'headers'  => $headers,
        ];

        return $this;
    }

    /**
     * Add raw file data to a POST request, which causes this to become a multipart form request.
     *
     * @param string      $key      Form field name.
     * @param string      $data     File data.
     * @param string|null $filename Filename to use in Content-Disposition header.
     * @param array       $headers  An associative array of headers.
     *
     * @throws \InvalidArgumentException
     *
     * @return self
     */
    public function addFileData(
        $key,
        $data,
        $filename,
        $headers = [])
    {
        $filename = basename($filename);
        // Default headers.
        $headers = $headers + [
            'Content-Type'              => 'application/octet-stream',
            'Content-Transfer-Encoding' => 'binary',
        ];
        $this->_files[$key] = [
            'contents' => $data,
            'filename' => $filename,
            'headers'  => $headers,
        ];

        return $this;
    }

    /**
     * Add custom header to request, overwriting any previous or default value.
     *
     * The custom value will even take precedence over the default headers!
     *
     * WARNING: If this is called multiple times with the same header "key"
     * name, it will only keep the LATEST value given for that specific header.
     * It will NOT keep any of its older values, since you can only have ONE
     * value per header! If you want multiple values in headers that support
     * it, you must manually format them properly and send us the final string,
     * usually by separating the value string entries with a semicolon.
     *
     * @param string $key
     * @param string $value
     *
     * @return self
     */
    public function addHeader(
        $key,
        $value)
    {
        $this->_headers[$key] = $value;

        return $this;
    }

    /**
     * Add headers used by most of API requests.
     *
     * @return self
     */
    protected function _addDefaultHeaders()
    {
        if ($this->_defaultHeaders) {
            $this->_headers['X-IG-Capabilities'] = Constants::X_IG_Capabilities;
            $this->_headers['X-IG-Connection-Type'] = Constants::X_IG_Connection_Type;
            $this->_headers['X-IG-Connection-Speed'] = mt_rand(1000, 3700).'kbps';
        }

        return $this;
    }

    /**
     * Set default headers flag.
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function setAddDefaultHeaders(
        $flag)
    {
        $this->_defaultHeaders = $flag;

        return $this;
    }

    /**
     * Set raw request body.
     *
     * @param StreamInterface $stream
     *
     * @return self
     */
    public function setBody(StreamInterface $stream)
    {
        $this->_body = $stream;

        return $this;
    }

    /**
     * Set authorized request flag.
     *
     * @param bool $needsAuth
     *
     * @return self
     */
    public function setNeedsAuth(
        $needsAuth)
    {
        $this->_needsAuth = $needsAuth;

        return $this;
    }

    /**
     * Set signed request flag.
     *
     * @param bool $signedPost
     *
     * @return self
     */
    public function setSignedPost(
        $signedPost = true)
    {
        $this->_signedPost = $signedPost;

        return $this;
    }

    /**
     * Return Stream for given file data.
     *
     * @param array $file
     *
     * @throws \BadMethodCallException
     *
     * @return StreamInterface
     */
    protected function _getStreamForFile(
        array $file)
    {
        if (isset($file['contents'])) {
            $result = stream_for($file['contents']);
        } elseif (isset($file['filepath'])) {
            $handle = fopen($file['filepath'], 'rb');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Can not open file "%s" for reading.', $file['filepath']));
            }
            $this->_handles[] = $handle;
            $result = stream_for($handle);
        } else {
            throw new \BadMethodCallException('No data for stream creation.');
        }

        return $result;
    }

    /**
     * Convert the request's data into its HTTP POST multipart body contents.
     *
     * @throws \RuntimeException
     * @throws \BadMethodCallException
     *
     * @return MultipartStream
     */
    protected function _getMultipartBody()
    {
        // Here is a tricky part: all form data (including files) must be ordered by hash code.
        // So we are creating an index for building POST data.
        $index = Utils::reorderByHashCode(array_merge($this->_posts, $this->_files));
        // Build multipart elements using created index.
        $elements = [];
        foreach ($index as $key => $value) {
            if (!isset($this->_files[$key])) {
                $element = [
                    'name'     => $key,
                    'contents' => $value,
                ];
            } else {
                $file = $this->_files[$key];
                $element = [
                    'name'     => $key,
                    'contents' => $this->_getStreamForFile($file),
                    'filename' => isset($file['filename']) ? $file['filename'] : null,
                    'headers'  => isset($file['headers']) ? $file['headers'] : [],
                ];
            }
            $elements[] = $element;
        }

        return new MultipartStream($elements, Utils::generateMultipartBoundary());
    }

    /**
     * Close opened file handles.
     */
    protected function _closeHandles()
    {
        if (!is_array($this->_handles) || !count($this->_handles)) {
            return;
        }

        foreach ($this->_handles as $handle) {
            fclose($handle);
        }
        $this->_resetHandles();
    }

    /**
     * Reset opened handles array.
     */
    protected function _resetHandles()
    {
        $this->_handles = [];
    }

    /**
     * Convert the request's data into its HTTP POST urlencoded body contents.
     *
     * @return Stream
     */
    protected function _getUrlencodedBody()
    {
        $this->_headers['Content-Type'] = Constants::CONTENT_TYPE;

        return stream_for(http_build_query(Utils::reorderByHashCode($this->_posts)));
    }

    /**
     * Convert the request's data into its HTTP POST body contents.
     *
     * @return StreamInterface|null The body stream if POST request; otherwise NULL if GET request.
     */
    protected function _getRequestBody()
    {
        // Check and return raw body stream if set.
        if ($this->_body !== null) {
            return $this->_body;
        }
        // We have no POST data and no files.
        if (!count($this->_posts) && !count($this->_files)) {
            return;
        }
        // Sign POST data if needed.
        if ($this->_signedPost) {
            $this->_posts = Signatures::signData($this->_posts);
        }
        // Switch between multipart (at least one file) or urlencoded body.
        if (!count($this->_files)) {
            $result = $this->_getUrlencodedBody();
        } else {
            $result = $this->_getMultipartBody();
        }

        return $result;
    }

    /**
     * Build HTTP request object.
     *
     * @return HttpRequest
     */
    protected function _buildHttpRequest()
    {
        $endpoint = $this->_url;
        // Determine the URI to use (it's either relative to API, or a full URI).
        if (strncmp($endpoint, 'http:', 5) !== 0 && strncmp($endpoint, 'https:', 6) !== 0) {
            $endpoint = Constants::API_URLS[$this->_apiVersion].$endpoint;
        }
        // Generate the final endpoint URL, by adding any custom query params.
        if (count($this->_params)) {
            $endpoint = $endpoint
                .(strpos($endpoint, '?') === false ? '?' : '&')
                .http_build_query(Utils::reorderByHashCode($this->_params));
        }
        // Add default headers.
        $this->_addDefaultHeaders();
        /** @var StreamInterface|null $postData The POST body stream; is NULL if GET request instead. */
        $postData = $this->_getRequestBody();
        // Determine request method.
        $method = $postData !== null ? 'POST' : 'GET';
        // Build HTTP request object.
        return new HttpRequest($method, $endpoint, $this->_headers, $postData);
    }

    /**
     * Helper which throws an error if not logged in.
     *
     * Remember to ALWAYS call this function at the top of any API request that
     * requires the user to be logged in!
     *
     * @throws \InstagramAPI\Exception\LoginRequiredException
     */
    protected function _throwIfNotLoggedIn()
    {
        // Check the cached login state. May not reflect what will happen on the
        // server. But it's the best we can check without trying the actual request!
        if (!$this->_parent->isLoggedIn) {
            throw new \InstagramAPI\Exception\LoginRequiredException('User not logged in. Please call login() and then try again.');
        }
    }

    /**
     * Perform the request and get its raw HTTP response.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getHttpResponse()
    {
        // Prevent request from sending multiple times.
        if ($this->_httpResponse === null) {
            if ($this->_needsAuth) {
                // Throw if this requires authentication and we're not logged in.
                $this->_throwIfNotLoggedIn();
            }

            $this->_resetHandles();
            try {
                $this->_httpResponse = $this->_parent->client->api($this->_buildHttpRequest());
            } finally {
                $this->_closeHandles();
            }
        }

        return $this->_httpResponse;
    }

    /**
     * Return JSON-decoded HTTP response.
     *
     * @param bool $assoc When TRUE, decode to associative array instead of object.
     *
     * @return mixed
     */
    public function getRawResponse(
        $assoc = false)
    {
        $httpResponse = $this->getHttpResponse();
        // Important: Special JSON decoder.
        return Client::api_body_decode((string) $httpResponse->getBody(), $assoc);
    }

    /**
     * Perform the request and map its response data to provided object.
     *
     * @param ResponseInterface $baseClass An instance of a class object whose properties to fill with the response.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return ResponseInterface An instance of baseClass.
     */
    public function getResponse(
        ResponseInterface $baseClass)
    {
        // Check for API response success and attempt to decode it to the desired class.
        $result = $this->_parent->client->getMappedResponseObject($baseClass, $this->getRawResponse(), $this->getHttpResponse());

        return $result;
    }
}
