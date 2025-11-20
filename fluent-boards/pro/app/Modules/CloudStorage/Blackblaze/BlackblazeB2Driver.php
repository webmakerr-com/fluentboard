<?php

namespace FluentBoardsPro\App\Modules\CloudStorage\Blackblaze;


use FluentBoardsPro\App\Modules\CloudStorage\Helper;
use FluentBoardsPro\App\Modules\CloudStorage\RemoteDriver;
use FluentBoardsPro\App\Modules\CloudStorage\S3\S3;

class BlackblazeB2Driver extends RemoteDriver
{

    protected $subFolder = '';
    public function __construct($accessKey, $secretKey, $endpoint, $bucket, $region = 'us-west-004')
    {
        parent::__construct($accessKey, $secretKey, $endpoint, $bucket, $region);
    }

    public function setSubFolder($subFolder)
    {
        $this->subFolder = $subFolder;
        return $this;
    }

    public function putObject($mediaPath)
    {
        $inputFile = Helper::inputFile($mediaPath);
        if (!$inputFile) {
            return new \WP_Error('file_not_found', 'File not found', []);
        }

        $s3Driver = $this->getDriver();

        $objectName = basename($mediaPath);

        if($this->subFolder) {
            $objectName = $this->subFolder . '/' . $objectName;
        }

        $response = $s3Driver::putObject($inputFile, $this->bucket, $objectName, S3::ACL_PUBLIC_READ);

        if (!$response || $response->code !== 200) {
            return new \WP_Error('s3_error', 'Error uploading file to S3', $response->error);
        }

        return [
            'public_url'  => $this->getPublicUrl($objectName),
            'remote_path' => $this->getRemotePath($objectName)
        ];
    }

    public function getPublicUrl($objectName)
    {
        return 'https://' . $this->endpoint . '/' . $this->bucket .'/'. $objectName;
    }

    public function getRemotePath($objectName)
    {
        return $this->getPublicUrl($objectName);
//        return 's3://' . $this->bucket . '.' . $this->endpoint . '/' . $objectName;
    }

    public function deleteObject($path)
    {
        $s3Driver = $this->getDriver();
        $s3Driver::deleteObject($this->bucket, $path);
    }

    public function testConnection()
    {
        // get files from the bucket
        $driver = $this->getDriver();

        try {
            $driver::getBucket($this->bucket, null, null, 1);
        } catch (\Exception $exception) {
            return new \WP_Error('s3_error', $exception->getMessage(), []);
        }

        return true;
    }

    public function getObject($path)
    {
        $s3 = $this->getDriver();

        // Extract the bucket name
        $parsedUrl = parse_url($path);
        // Extract the object key
        $path = $parsedUrl['path'];
        $parts = explode('/', $path);
        $fileBucket = $parts[1];

        $fileName = $parts[2];
//        if ($this->subFolder) {
//            $fileName = $this->subFolder . '/' . $fileName;
//        }

        // Get the object
//        $response = $s3::getObject($this->bucket, $objectKey);
        $response = $s3::getObject($fileBucket, $fileName);
        return $response;

    }

}
