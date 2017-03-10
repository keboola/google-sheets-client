<?php
/**
 * Author: miro@keboola.com
 * Date: 10/03/2017
 */

namespace Keboola\GoogleSheetsClient;

use GuzzleHttp\Exception\ClientException;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;

class Client
{
    /** @var GoogleApi */
    protected $api;

    const URI_DRIVE_FILES = 'https://www.googleapis.com/drive/v3/files';

    const URI_DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    const URI_SPREADSHEETS = 'https://sheets.googleapis.com/v4/spreadsheets/';

    const MIME_TYPE_SPREADSHEET = 'application/vnd.google-apps.spreadsheet';

    public function __construct(GoogleApi $api)
    {
        $this->api = $api;
    }

    /**
     * @return GoogleApi
     */
    public function getApi()
    {
        return $this->api;
    }

    /**
     * @param $fileId
     * @param array $fields
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function getFile($fileId, $fields = [])
    {
        $uri = self::URI_DRIVE_FILES . '/' . $fileId;
        if (!empty($fields)) {
            $uri .= sprintf('?fields=%s', implode(',', $fields));
        }
        $response = $this->api->request($uri);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param string $query
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function listFiles($query = '')
    {
        $uri = self::URI_DRIVE_FILES;
        if (!empty($query)) {
            $uri .= sprintf('?q=%s', $query);
        }
        $response = $this->api->request($uri);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $pathname
     * @param $title
     * @param array $params
     * @return array
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function createFile($pathname, $title, $params = [])
    {
        $fileMetadata = $this->createFileMetadata($title, $params);

        $mediaUrl = sprintf('%s/%s?uploadType=media', self::URI_DRIVE_UPLOAD, $fileMetadata['id']);

        $response = $this->api->request(
            $mediaUrl,
            'PATCH',
            [
                'Content-Type' => 'text/csv',
                'Content-Length' => filesize($pathname)
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r'))
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $title
     * @param $params
     * @return mixed
     */
    public function createFileMetadata($title, $params)
    {
        $body = [
            'name' => $title
        ];

        $response = $this->api->request(
            self::URI_DRIVE_FILES,
            'POST',
            [
                'Content-Type' => 'application/json',
            ],
            [
                'json' => array_merge($body, $params)
            ]
        );

        return json_decode($response->getBody(), true);
    }

    /**
     * @param $fileId
     * @param $pathname
     * @param $params
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function updateFile($fileId, $pathname, $params)
    {
        // update metadata
        $responseJson = $this->updateFileMetadata($fileId, $params);

        $response = $this->api->request(
            sprintf('%s/%s?uploadType=media', self::URI_DRIVE_UPLOAD, $responseJson['id']),
            'PATCH',
            [
                'Content-Type' => 'text/csv',
                'Content-Length' => filesize($pathname)
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r'))
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $fileId
     * @param array $body
     * @param array $params
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function updateFileMetadata($fileId, $body = [], $params = [])
    {
        $uri = sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId);
        if (!empty($params)) {
            $uri .= '?' . \GuzzleHttp\Psr7\build_query($params);
        }

        $response = $this->api->request(
            $uri,
            'PATCH',
            [
                'Content-Type' => 'application/json',
            ],
            [
                'json' => $body
            ]
        );

        return json_decode($response->getBody(), true);
    }

    /**
     * @param $fileId
     * @return \GuzzleHttp\Psr7\Response
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function deleteFile($fileId)
    {
        return $this->api->request(
            sprintf('%s/%s', self::URI_DRIVE_FILES, $fileId),
            'DELETE'
        );
    }

    /**
     * @param $fileId
     * @param string $mimeType
     * @return \GuzzleHttp\Psr7\Response
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function exportFile($fileId, $mimeType = 'text/csv')
    {
        return $this->api->request(
            sprintf(
                '%s/%s/export?mimeType=%s',
                self::URI_DRIVE_FILES,
                $fileId,
                $mimeType
            )
        );
    }

    /**
     * Returns list of sheet for given document
     *
     * @param $fileId
     * @return array|bool
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function getSpreadsheet($fileId)
    {
        $response = $this->api->request(
            sprintf('%s%s', self::URI_SPREADSHEETS, $fileId),
            'GET',
            [
                'Accept' => 'application/json'
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $spreadsheetId
     * @param $range
     * @param array $params
     * @return array
     */
    public function getSpreadsheetValues($spreadsheetId, $range, $params = [])
    {
        $uri = sprintf('%s%s/values/%s', self::URI_SPREADSHEETS, $spreadsheetId, $range);
        if (!empty($params)) {
            $uri .= '?' . \GuzzleHttp\Psr7\build_query($params);
        }

        $response = $this->api->request(
            $uri,
            'GET',
            [
                'Accept' => 'application/json'
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $fileProperties
     * @param $sheets
     * @param null $fileId
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function createSpreadsheet($fileProperties, $sheets, $fileId = null)
    {
        $body = [
            'properties' => $fileProperties,
            'sheets' => $sheets
        ];

        if ($fileId !== null) {
            $body['spreadsheetId'] = $fileId;
        }

        $response = $this->api->request(
            self::URI_SPREADSHEETS,
            'POST',
            [
                'Accept' => 'application/json',
            ],
            [
                'json' => $body
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $spreadsheetId
     * @param $sheet
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function addSheet($spreadsheetId, $sheet)
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'addSheet' => $sheet
                ]
            ]
        ]);
    }

    public function updateSheet($spreadsheetId, $properties)
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'updateSheetProperties' => [
                        'properties' => $properties,
                        'fields' => 'title'
                    ]
                ]
            ]
        ]);
    }

    /**
     * @param $spreadsheetId
     * @param $sheetId
     * @return mixed
     */
    public function deleteSheet($spreadsheetId, $sheetId)
    {
        return $this->batchUpdateSpreadsheet($spreadsheetId, [
            'requests' => [
                [
                    'deleteSheet' => [
                        'sheetId' => $sheetId
                    ]
                ]
            ]
        ]);
    }

    /**
     * Batch Update Spreadsheet Metadata
     *
     * @param $spreadsheetId
     * @param $body
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function batchUpdateSpreadsheet($spreadsheetId, $body)
    {
        $response = $this->api->request(
            sprintf(
                '%s%s:batchUpdate',
                self::URI_SPREADSHEETS,
                $spreadsheetId
            ),
            'POST',
            [
                'Accept' => 'application/json',
            ],
            [
                'json' => $body
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $spreadsheetId
     * @param $range
     * @param $values
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function updateSpreadsheetValues($spreadsheetId, $range, $values)
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s?valueInputOption=USER_ENTERED',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range
            ),
            'PUT',
            [
                'Accept' => 'application/json',
            ],
            [
                'json' => [
                    'values' => $values
                ]
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $spreadsheetId
     * @param $range
     * @param $values
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function appendSpreadsheetValues($spreadsheetId, $range, $values)
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s:append?valueInputOption=USER_ENTERED',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range
            ),
            'POST',
            [
                'Accept' => 'application/json',
            ],
            [
                'json' => [
                    'range' => $range,
                    'majorDimension' => 'ROWS',
                    'values' => $values
                ]
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    public function clearSpreadsheetValues($spreadsheetId, $range)
    {
        $response = $this->api->request(
            sprintf(
                '%s%s/values/%s:clear',
                self::URI_SPREADSHEETS,
                $spreadsheetId,
                $range
            ),
            'POST',
            [
                'Accept' => 'application/json',
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param int $count
     * @return mixed
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function generateIds($count = 10)
    {
        $response = $this->api->request(
            sprintf('%s/generateIds?count=%s', self::URI_DRIVE_FILES, $count)
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param $fileId
     * @return bool
     */
    public function fileExists($fileId)
    {
        try {
            $this->getFile($fileId);
            return true;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
        return false;
    }
}
