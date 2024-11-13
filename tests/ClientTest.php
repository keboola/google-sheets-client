<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsClient\Tests;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /** @var string */
    protected $dataPath = __DIR__ . '/data';

    /** @var Client */
    protected $client;

    public function setUp(): void
    {
        $api = new RestApi((string) getenv('CLIENT_ID'), (string) getenv('CLIENT_SECRET'));
        $api->setCredentials((string) getenv('ACCESS_TOKEN'), (string) getenv('REFRESH_TOKEN'));
        $api->setBackoffsCount(2); // Speeds up the tests
        $this->client = new Client($api);
    }

    public function testGenerateIds(): void
    {
        $ids = $this->client->generateIds();
        $this->assertNotEmpty($ids);
        $this->assertArrayHasKey('ids', $ids);
        $this->assertCount(10, $ids['ids']);
    }

    public function testFileExists(): void
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic'
        );
        $exists = $this->client->fileExists($gdFile['id']);
        $this->assertTrue($exists);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testCreateFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $this->assertArrayHasKey('id', $gdFile);
        $this->assertArrayHasKey('name', $gdFile);
        $this->assertArrayHasKey('kind', $gdFile);
        $this->assertEquals('titanic', $gdFile['name']);
        $this->assertEquals('drive#file', $gdFile['kind']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testCreateFileWithConversion(): void
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ]
        );
        $this->assertArrayHasKey('id', $gdFile);
        $this->assertArrayHasKey('name', $gdFile);
        $this->assertArrayHasKey('kind', $gdFile);
        $this->assertEquals('titanic', $gdFile['name']);
        $this->assertEquals('drive#file', $gdFile['kind']);

        $fileMeta = $this->client->getFile($gdFile['id']);
        self::assertEquals('application/vnd.google-apps.spreadsheet', $fileMeta['mimeType']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testCreateFileInFolder(): void
    {
        $folderId = getenv('GOOGLE_DRIVE_FOLDER');
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [$folderId],
            ]
        );

        $gdFile = $this->client->getFile($gdFile['id']);
        $this->assertArrayHasKey('id', $gdFile);
        $this->assertArrayHasKey('name', $gdFile);
        $this->assertArrayHasKey('parents', $gdFile);
        $this->assertContains($folderId, $gdFile['parents']);
        $this->assertEquals('titanic', $gdFile['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testGetFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $file = $this->client->getFile($gdFile['id']);

        $this->assertArrayHasKey('id', $file);
        $this->assertArrayHasKey('name', $file);
        $this->assertArrayHasKey('parents', $file);
        $this->assertEquals('titanic', $file['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $res = $this->client->updateFile($gdFile['id'], $this->dataPath . '/titanic.csv', [
            'name' => $gdFile['name'] . '_changed',
        ]);

        $this->assertArrayHasKey('id', $res);
        $this->assertArrayHasKey('name', $res);
        $this->assertArrayHasKey('kind', $res);
        $this->assertArrayHasKey('parents', $res);
        $this->assertEquals($gdFile['id'], $res['id']);
        $this->assertEquals($gdFile['name'] . '_changed', $res['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testDeleteFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $this->client->deleteFile($gdFile['id']);

        $this->expectException('GuzzleHttp\\Exception\\ClientException');
        $this->client->getFile($gdFile['id']);
    }

    public function testCreateSheet(): void
    {
        $res = $this->client->createSpreadsheet(
            ['title' => 'titanic'],
            ['properties' => ['title' => 'my_test_sheet']]
        );

        $this->assertArrayHasKey('spreadsheetId', $res);
        $this->assertArrayHasKey('properties', $res);
        $this->assertArrayHasKey('sheets', $res);
        $this->assertEquals('titanic', $res['properties']['title']);
        $this->assertCount(1, $res['sheets']);
        $sheet = array_shift($res['sheets']);
        $this->assertArrayHasKey('properties', $sheet);
        $this->assertArrayHasKey('sheetId', $sheet['properties']);
        $this->assertArrayHasKey('title', $sheet['properties']);
        $this->assertEquals('my_test_sheet', $sheet['properties']['title']);

        $this->client->deleteFile($res['spreadsheetId']);
    }

    public function testAddSheet(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ]
        );
        $res = $this->client->addSheet($spreadsheet['spreadsheetId'], [
            'properties' => ['title' => 'sheet_2'],
        ]);

        $this->assertArrayHasKey('spreadsheetId', $res);
        $this->assertArrayHasKey('replies', $res);

        $res2 = $this->client->getSpreadsheet($spreadsheet['spreadsheetId']);
        $this->assertCount(2, $res2['sheets']);

        $this->client->deleteFile($spreadsheet['spreadsheetId']);
    }

    public function testGetSheet(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ]
        );
        $spreadsheet = $this->client->getSpreadsheet($spreadsheet['spreadsheetId']);

        $this->assertArrayHasKey('spreadsheetId', $spreadsheet);
        $this->assertArrayHasKey('properties', $spreadsheet);
        $this->assertArrayHasKey('sheets', $spreadsheet);

        $this->client->deleteFile($spreadsheet['spreadsheetId']);
    }

    public function testGetSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ]
        );
        $this->client->updateSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            'sheet_1',
            $this->csvToArray($this->dataPath . '/titanic.csv'),
        );

        $response = $this->client->getSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            $spreadsheet['sheets'][0]['properties']['title']
        );

        $this->assertArrayHasKey('range', $response);
        $this->assertArrayHasKey('majorDimension', $response);
        $this->assertArrayHasKey('values', $response);
        $header = $response['values'][0];
        $this->assertEquals('Class', $header[1]);
        $this->assertEquals('Sex', $header[2]);
        $this->assertEquals('Age', $header[3]);
        $this->assertEquals('Survived', $header[4]);
        $this->assertEquals('Freq', $header[5]);

        $this->client->deleteFile($spreadsheet['spreadsheetId']);
    }

    public function testUpdateSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ]
        );

        $values = $this->csvToArray($this->dataPath . '/titanic_2.csv');

        $response =$this->client->updateSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            $spreadsheet['sheets'][0]['properties']['title'],
            $values
        );

        $this->assertArrayHasKey('spreadsheetId', $response);
        $this->assertArrayHasKey('updatedRange', $response);
        $this->assertArrayHasKey('updatedRows', $response);
        $this->assertArrayHasKey('updatedColumns', $response);
        $this->assertArrayHasKey('updatedCells', $response);

        $this->assertEquals($spreadsheet['spreadsheetId'], $response['spreadsheetId']);

        $gdValues = $this->client->getSpreadsheetValues(
            $response['spreadsheetId'],
            $response['updatedRange']
        );

        $this->assertEquals($values, $gdValues['values']);

        $this->client->deleteFile($spreadsheet['spreadsheetId']);
    }

    public function testAppendSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ]
        );
        $this->client->updateSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            'sheet_1',
            $this->csvToArray($this->dataPath . '/titanic_1.csv'),
        );

        $values = $this->csvToArray($this->dataPath . '/titanic_2.csv');
        array_shift($values); // skip header

        $response =$this->client->appendSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            $spreadsheet['sheets'][0]['properties']['title'],
            $values
        );

        $expectedValues = $this->csvToArray($this->dataPath . '/titanic.csv');
        $gdValues = $this->client->getSpreadsheetValues(
            $response['spreadsheetId'],
            $spreadsheet['sheets'][0]['properties']['title']
        );
        $this->assertEquals($expectedValues, $gdValues['values']);

        $this->client->deleteFile($spreadsheet['spreadsheetId']);
    }

    public function testClearSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ]
        );
        $this->client->updateSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            'sheet_1',
            $this->csvToArray($this->dataPath . '/titanic.csv'),
        );
        $sheetTitle = $spreadsheet['sheets'][0]['properties']['title'];

        $this->client->clearSpreadsheetValues($spreadsheet['spreadsheetId'], $sheetTitle);
        $values = $this->client->getSpreadsheetValues($spreadsheet['spreadsheetId'], $sheetTitle);

        $this->assertArrayNotHasKey('values', $values);
    }

    public function testCreateFileInTeamFolder(): void
    {
        $this->client->setTeamDriveSupport(true);
        $folderId = getenv('GOOGLE_DRIVE_TEAM_FOLDER');
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [$folderId],
            ]
        );

        $gdFile = $this->client->getFile($gdFile['id']);
        $this->assertArrayHasKey('id', $gdFile);
        $this->assertArrayHasKey('name', $gdFile);
        $this->assertArrayHasKey('parents', $gdFile);
        $this->assertContains($folderId, $gdFile['parents']);
        $this->assertEquals('titanic', $gdFile['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testGetTeamFile(): void
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_TEAM_FOLDER')],
            ]
        );
        $file = $this->client->getFile($gdFile['id']);

        $this->assertArrayHasKey('id', $file);
        $this->assertArrayHasKey('name', $file);
        $this->assertArrayHasKey('parents', $file);
        $this->assertEquals('titanic', $file['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateTeamFile(): void
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                getenv('GOOGLE_DRIVE_TEAM_FOLDER'),
            ]
        );
        $res = $this->client->updateFile($gdFile['id'], $this->dataPath . '/titanic.csv', [
            'name' => $gdFile['name'] . '_changed',
        ]);

        $this->assertArrayHasKey('id', $res);
        $this->assertArrayHasKey('name', $res);
        $this->assertArrayHasKey('kind', $res);
        $this->assertArrayHasKey('parents', $res);
        $this->assertEquals($gdFile['id'], $res['id']);
        $this->assertEquals($gdFile['name'] . '_changed', $res['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testDeleteTeamFile(): void
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                getenv('GOOGLE_DRIVE_TEAM_FOLDER'),
            ]
        );
        $this->client->deleteFile($gdFile['id']);

        $this->expectException('GuzzleHttp\\Exception\\ClientException');
        $this->client->getFile($gdFile['id']);
    }

    protected function csvToArray(string $pathname): array
    {
        return array_map('str_getcsv', (array) file($pathname));
    }
}
