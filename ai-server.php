<?php

class AI_Server
{
    const RESOLUTION_COOKIE = 'resolution';

    protected $_config = array();

    public function __construct($config = array())
    {
        $this->_config = array_merge(array(
            '_cachePath'   => dirname(__FILE__) . '/cache/',
            '_useEtags'    => true,
            '_watchCache'  => true,
            '_resolutions' => array(1382, 992, 768, 480),
            '_jpegQuality' => 75,
            '_sharpen'     => true,
            '_expires'     => 60*60*24*7,
        ), $config);
    }

    public function getConfig()
    {
        return $this->_config;
    }

    public function __isset($key)
    {
        return isset($this->_config[$key]);
    }

    public function __get($key)
    {
        return $this->__isset($key) ? $this->_config[$key] : null;
    }

    public function go()
    {
        $documentRoot  = $_SERVER['DOCUMENT_ROOT'];
        $requestedUri  = parse_url(urldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $requestedFile = basename($requestedUri);
        $sourceFile    = $documentRoot . $requestedUri;

        if(!file_exists($sourceFile))
        {
            header('Status: 404 Not Found');
            exit();
        }

        $extension = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));

        /* check that PHP has the GD library available to use for image re-sizing */
        if (!extension_loaded('gd'))
        {
            if (!function_exists('dl') || !dl('gd.so'))
            {
                // no GD available, so deliver the image straight up
                trigger_error('You must enable the GD extension to make use of Adaptive Images', E_USER_WARNING);
                $this->_serveImage($sourceFile, $extension);
            }
        }

        $this->_createDir($this->_cachePath);

        $resolution = $this->_getResolution();

        /* if the requested URL starts with a slash, remove the slash */
        if(substr($requestedUri, 0,1) == '/')
            $requestedUri = substr($requestedUri, 1);


        $cacheFile = $this->_cachePath . $resolution . '/' . md5($requestedUri) . '.' . $extension;

        if(file_exists($cacheFile) && (!$this->_watchCache || !$this->_isStale($cacheFile)))
        {
            $this->_serveImage($cacheFile, $extension);
        }
        else
        {
            $file = $this->_generateImage($sourceFile, $cacheFile, $resolution, $extension);
            $this->_serveImage($file, $extension);
        }
    }

    protected function _isMobile()
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
        return strpos($ua, 'mobile');
    }

    protected function _getResolution()
    {
        $resolutions = $this->_resolutions;
        $resolution = 0;

        /* Check to see if a valid cookie exists */
        if (isset($_COOKIE['resolution']))
        {
            $value = $_COOKIE[self::RESOLUTION_COOKIE];

            // does the cookie look valid? [whole number, comma, potential floating number]
            if (!preg_match("/^[0-9]+[,]*[0-9\.]+$/", $value)) // no it doesn't look valid
            {
                setcookie(self::RESOLUTION_COOKIE, '', time()-100); // delete the mangled cookie
            }
            else // the cookie is valid, do stuff with it
            {
                $data = explode(',', $value);
                $width  = (int)$data[0]; // the base resolution (CSS pixels)

                if(isset($_GET['width']))
                    $width = (int)$_GET['width'];

                $totalWidth = $width;
                $pixelDensity = isset($data[1]) ? (int)$data[1] : 1;
                
                rsort($resolutions); // make sure the supplied break-points are in reverse size order
                $resolution = $resolutions[0]; // by default use the largest supported break-point

                $requiredWidth = $width * $pixelDensity;

                foreach($resolutions as $bp)
                {
                    if($requiredWidth <= $bp)
                        $resolution = $bp;
                }

                $resolution *= $pixelDensity;
            }
        }

        if(!$resolution) $resolution = $this->_isMobile() ? min($resolutions) : max($resolutions);

        return $resolution;
    }

    protected function _findSharp($intOrig, $intFinal)
    {
        $intFinal = $intFinal * (750.0 / $intOrig);
        $intA     = 52;
        $intB     = -0.27810650887573124;
        $intC     = .00047337278106508946;
        $intRes   = $intA + $intB * $intFinal + $intC * $intFinal * $intFinal;
        return max(round($intRes), 0);
    }

    protected function _generateImage($sourceFile, $cacheFile, $res, $extension)
    {
        // Check the image dimensions
        $dimensions   = GetImageSize($sourceFile);
        $width        = $dimensions[0];
        $height       = $dimensions[1];

        // Do we need to downscale the image?
        if ($width <= $res) // no, because the width of the source image is already less than the client width
            return $sourceFile;

        // We need to resize the source image to the width of the resolution breakpoint we're working with
        $ratio     = $height/$width;
        $newWidth  = $res;
        $newHeight = ceil($newWidth * $ratio);
        $dst       = ImageCreateTrueColor($newWidth, $newHeight); // re-sized image

        switch ($extension)
        {
            case 'png':
                $src = @ImageCreateFromPng($sourceFile); // original image
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
                break;
            case 'gif':
                $src = @ImageCreateFromGif($sourceFile); // original image
                break;
            default:
                $src = @ImageCreateFromJpeg($sourceFile); // original image
                ImageInterlace($dst, true); // Enable interlacing (progressive JPG, smaller size file)
        }

        ImageCopyResampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height); // do the resize in memory
        ImageDestroy($src);

        // sharpen the image?
        // NOTE: requires PHP compiled with the bundled version of GD (see http://php.net/manual/en/function.imageconvolution.php)
        if($this->_sharpen && function_exists('imageconvolution'))
        {
            $intSharpness = $this->_findSharp($width, $newWidth);
            $arrMatrix = array(
                array(-1, -2, -1),
                array(-2, $intSharpness + 12, -2),
                array(-1, -2, -1)
            );
            imageconvolution($dst, $arrMatrix, $intSharpness, 0);
        }

        $cacheDir = dirname($cacheFile);

        if(!$this->_createDir($cacheDir))
        {
            ImageDestroy($dst);
            $this->_sendErrorImage('Failed to create cache directory: ' . $cacheDir);
        }

        if(!is_writable($cacheDir))
            $this->_sendErrorImage('The cache directory is not writable: ' . $cacheDir);

        // save the new file in the appropriate path, and send a version to the browser
        switch ($extension)
        {
            case 'png':
                $gotSaved = ImagePng($dst, $cacheFile);
                break;
            case 'gif':
                $gotSaved = ImageGif($dst, $cacheFile);
                break;
            default:
                $gotSaved = ImageJpeg($dst, $cacheFile, $this->_jpegQuality);
                break;
        }

        ImageDestroy($dst);

        if (!$gotSaved && !file_exists($cacheFile))
            $this->_sendErrorImage('Failed to create image: ' . $cacheFile);

        return $cacheFile;
    }

    protected function _generateEtag($filename)
    {
        return md5($filename . filemtime($filename));
    }

    protected function _serveImage($filename, $extension)
    {
        $notModified = false;
        $etag = false;

        if($this->_useEtags)
        {
            $etag = $this->_generateEtag($filename);
            if($header = $this->_getRequestHeader('If-None-Match'))
            {
                $notModified = $header == $etag;
            }
        }

        if($notModified)
            header('HTTP/1.1 304 Not Modified');

        if($etag)
            header('Etag: ' . $etag);

        header('Cache-Control: public, max-age=' . $this->_browserCacheExpires);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $this->_browserCacheExpires) . ' GMT');

        if(!$notModified)
        {
            if (in_array($extension, array('png', 'gif', 'jpeg')))
                header('Content-Type: image/' . $extension);
            else
                header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($filename));
            readfile($filename);
        }

        exit();
    }

    protected function _sendErrorImage($message)
    {
        $document_root  = $_SERVER['DOCUMENT_ROOT'];
        $requested_uri  = parse_url(urldecode($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $requested_file = basename($requested_uri);
        $source_file    = $document_root.$requested_uri;

        $is_mobile = $this->_isMobile ? 'TRUE' : 'FALSE';

        $im            = ImageCreateTrueColor(800, 300);
        $text_color    = ImageColorAllocate($im, 233, 14, 91);
        $message_color = ImageColorAllocate($im, 91, 112, 233);

        ImageString($im, 5, 5, 5, "Adaptive Images encountered a problem:", $text_color);
        ImageString($im, 3, 5, 25, $message, $message_color);

        ImageString($im, 5, 5, 85, "Potentially useful information:", $text_color);
        ImageString($im, 3, 5, 105, "DOCUMENT ROOT IS: $document_root", $text_color);
        ImageString($im, 3, 5, 125, "REQUESTED URI WAS: $requested_uri", $text_color);
        ImageString($im, 3, 5, 145, "REQUESTED FILE WAS: $requested_file", $text_color);
        ImageString($im, 3, 5, 165, "SOURCE FILE IS: $source_file", $text_color);
        ImageString($im, 3, 5, 185, "DEVICE IS MOBILE? $is_mobile", $text_color);

        header('Cache-Control: no-store');
        header('Expires: '.gmdate('D, d M Y H:i:s', time()-1000).' GMT');
        header('Content-Type: image/jpeg');
        ImageJpeg($im);
        ImageDestroy($im);
        exit();
    }

    /*
    protected function _getRequestHeaders()
    {
        return function_exists('getallheaders') ? getallheaders() : array();
    }
    */

    protected function _getRequestHeader($header)
    {
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        return isset($_SERVER[$header]) ? $_SERVER[$header] : null;
    }

    protected function _createDir($path)
    {
        if (!is_dir($path))
        {
            if (!mkdir($path, 0755, true)) // so make it
                return is_dir($path); // check again to protect against race conditions
        }

        return true;
    }

    protected function _isStale($cacheFile, $sourceFile)
    {
        return (!file_exists($cacheFile) || filemtime($cacheFile) >= filemtime($sourceFile));
    }
}
