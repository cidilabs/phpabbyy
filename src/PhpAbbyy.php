<?php

namespace CidiLabs\PhpAbbyy;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;
use DOMDocument;

class PhpAbbyy
{
    private $client;
    private $workflows = array(
        'html' => 'html-conversion',
        'txt' => 'txt-conversion',
        'epub' => 'epub-conversion',
        'pdf' => 'pdf-conversion',
    );

    //Map file endings, this is a special case just for abbyy outputting html as htm
    private $fileEndings = array(
        'html' => 'htm',
        'txt' => 'txt',
        'epub' => 'epub',
        'doc' => 'docx',
        'pdf' => 'pdf',
    );


    private $apiPath = '/FineReaderServer14/api';

    // Response object
    private $responseObject = [
        'data' => [
            'taskId' => '',
            'filePath' => '',
            'relatedFiles' => [],
            'status' => ''
        ],
        'errors' => []
    ];

    private $outputDir;
    private $path;


    public function __construct($outputDir = 'alternates')
    {
        $this->client = HttpClient::create([
            'base_uri' => $_ENV['ABBYY_DOMAIN'],
        ]);
        $this->outputDir = $outputDir;
    }

    public function supports()
    {
        return [
            'input' => ['pdf', 'doc'],
            'output' => ['html', 'pdf', 'epub']
        ];
    }

    public function getPostOptions($options)
    {
        return [
            'json' => [
                'FileName' => $options['fileName'],
                'FileContents' => base64_encode(file_get_contents($options['fileUrl'])),
            ]
        ];
    }

    public function convertFile($options)
    {
        $format = strtolower($options['format']);
        $postOptions = $this->getPostOptions($options);

        $response = $this->client->request('POST', "{$this->apiPath}/workflows/{$this->workflows[$format]}/input/file", $postOptions);

        if ($response->getStatusCode() === Response::HTTP_CREATED) {
            $this->responseObject['data']['taskId'] = trim($response->getContent(false), "\"");
        } else {
            $this->responseObject['errors'][] = "Abby Failed to create the file";
        }
        return $this->responseObject;
    }

    public function isReady($jobId): bool
    {
        $response = $this->client->request('GET', "{$this->apiPath}/jobs/{$jobId}");
        $contentStr = $response->getContent(false);
        $jobStatus = \json_decode($contentStr, true);

        return ($jobStatus['State'] === "JS_Complete");
    }

    public function getFileUrl($jobId, $options = [])
    {
        $response = $this->client->request('GET', "{$this->apiPath}/jobs/{$jobId}/result/files");
        $inputExt = isset($options['input']) ? $options['input'] : 'pdf';
        $outputExt = isset($options['output']) ? $options['output'] : 'html';

        if ($response->getStatusCode() === Response::HTTP_OK) {
            $contentStr = $response->getContent(false);
            $this->path = getcwd() . '/' . $this->outputDir;
            if (!is_dir($this->path)) {
                mkdir($this->path, 0755);
            }
            $archivePath = $this->path . '/' . $jobId . '.zip';
            file_put_contents($archivePath, $contentStr);
            $files = $this->unZipFile($archivePath);


            foreach ($files as $file) {
                if (str_ends_with($file, $this->fileEndings[$outputExt])) {
                    $this->responseObject['data']['filePath'] = $file;
                } else if (str_ends_with($file, $this->fileEndings[$inputExt])) {
                    unlink($file);
                    continue;
                } else {
                    $this->responseObject['data']['relatedFiles'][] = $file;
                }
            }

            if($this->fileEndings[$outputExt] == 'htm' && !empty($this->responseObject['data']['filePath'])){
                $this->AttachPicturesToFileBase64($this->responseObject['data']['filePath']);
            }

            foreach ($this->responseObject['data']['relatedFiles'] as $file) {
                unlink($file);
            }
            $this->responseObject['data']['relatedFiles'] = [];

            if (!empty($this->responseObject['data']['filePath'])) {
                $this->deleteConvertedFileFromAbby($jobId);
                unlink($archivePath);
            } else {
                $this->responseObject['errors'][] = "No files found from zip from taskId: {$jobId}";
            }
        } else {
            $this->responseObject['errors'][] = "Status code was not HTTP_OK 200 : {$jobId}";
        }

        return $this->responseObject;
    }

    protected function deleteConvertedFileFromAbby($jobId)
    {
        $response = $this->client->request('DELETE', "{$this->apiPath}/jobs/{$jobId}");

        return ($response->getStatusCode() === Response::HTTP_NO_CONTENT);
    }

    private function AttachPicturesToFileBase64($file)
    {
        $html = file_get_contents($file);

        $dom = $this->getDomDocument($html);
        foreach($dom->getElementsByTagName('body')[0]->getElementsByTagName('img') as $img) {
            $imgFileLocation = $this->path . '/' . $img->getAttribute('src');

            $imageBase64 = base64_encode(file_get_contents($imgFileLocation));

            $img->setAttribute('src',"data:image/png;base64,{$imageBase64}");
        }

        file_put_contents($file,$dom->saveHTML());

    }

    private function unZipFile($fileUrl)
    {
        $fileNames = $filePaths = [];
        $path = pathinfo(realpath($fileUrl), PATHINFO_DIRNAME);

        $zip = new ZipArchive;
        $res = $zip->open($fileUrl);

        if ($res === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fileNames[] = $zip->getNameIndex($i);
            }

            // extract it to the path we determined above
            $zip->extractTo($path, $fileNames);
            $zip->close();
        } else {
            $this->responseObject['errors'][] = "couldn't open file";
        }

        foreach ($fileNames as $i => $file) {
            $filePath = $path . '/' . $file;

            if (file_exists($filePath)) {
                $filePaths[] = $filePath;
            }
        }

        return $filePaths;
    }

    public function deleteFile($fileUrl)
    {

        if (file_exists($fileUrl)) {
            unlink($fileUrl);
        } else {
            $this->responseObject['errors'][] = "File not found";
        }
        return $this->responseObject;
    }

    private function getDomDocument($html)
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        return $dom;
    }

}
