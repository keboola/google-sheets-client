<?php
/**
 * Author: miro@keboola.com
 * Date: 10/3/2017
 */
namespace Keboola\GoogleDriveWriter\Tests;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    protected $dataPath = __DIR__ . '/../../data';

    /** @var Client */
    protected $client;

    public function setUp()
    {
        $api = new RestApi(getenv('CLIENT_ID'), getenv('CLIENT_SECRET'));
        $api->setCredentials(getenv('ACCESS_TOKEN'), getenv('REFRESH_TOKEN'));
        $api->setBackoffsCount(2); // Speeds up the tests
        $this->client = new Client($api);
    }

    public function testGenerateIds()
    {
        $ids = $this->client->generateIds();
        $this->assertNotEmpty($ids);
        $this->assertArrayHasKey('ids', $ids);
        $this->assertCount(10, $ids['ids']);
    }

    public function testFileExists()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic'
        );
        $exists = $this->client->fileExists($gdFile['id']);
        $this->assertTrue($exists);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testCreateFile()
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $this->assertArrayHasKey('id', $gdFile);
        $this->assertArrayHasKey('name', $gdFile);
        $this->assertArrayHasKey('kind', $gdFile);
        $this->assertEquals('titanic', $gdFile['name']);
        $this->assertEquals('drive#file', $gdFile['kind']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testCreateFileInFolder()
    {
        $folderId = getenv('GOOGLE_DRIVE_FOLDER');
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [$folderId]
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

    public function testGetFile()
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $file = $this->client->getFile($gdFile['id']);

        $this->assertArrayHasKey('id', $file);
        $this->assertArrayHasKey('name', $file);
        $this->assertArrayHasKey('parents', $file);
        $this->assertEquals('titanic', $file['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateFile()
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $res = $this->client->updateFile($gdFile['id'], $this->dataPath . '/titanic.csv', [
            'name' => $gdFile['name'] . '_changed'
        ]);

        $this->assertArrayHasKey('id', $res);
        $this->assertArrayHasKey('name', $res);
        $this->assertArrayHasKey('kind', $res);
        $this->assertArrayHasKey('parents', $res);
        $this->assertEquals($gdFile['id'], $res['id']);
        $this->assertEquals($gdFile['name'] . '_changed', $res['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testDeleteFile()
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $this->client->deleteFile($gdFile['id']);

        $this->expectException('GuzzleHttp\\Exception\\ClientException');
        $this->client->getFile($gdFile['id']);
    }

    public function testCreateSheet()
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

    public function testAddSheet()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );
        $res = $this->client->addSheet($gdFile['id'], [
            'properties' => ['title' => 'sheet_2']
        ]);

        $this->assertArrayHasKey('spreadsheetId', $res);
        $this->assertArrayHasKey('replies', $res);
        $this->assertEquals($gdFile['id'], $res['spreadsheetId']);

        $res2 = $this->client->getSpreadsheet($gdFile['id']);

        $this->assertArrayHasKey('spreadsheetId', $res2);
        $this->assertEquals($gdFile['id'], $res2['spreadsheetId']);
        $this->assertCount(2, $res2['sheets']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testGetSheet()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );
        $spreadsheet = $this->client->getSpreadsheet($gdFile['id']);

        $this->assertArrayHasKey('spreadsheetId', $spreadsheet);
        $this->assertArrayHasKey('properties', $spreadsheet);
        $this->assertArrayHasKey('sheets', $spreadsheet);

        $this->client->deleteFile($spreadsheet['spreadsheetId']);
    }

    public function testGetSheetValues()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );
        $gdSheet = $this->client->getSpreadsheet($gdFile['id']);
        $response = $this->client->getSpreadsheetValues(
            $gdFile['id'],
            $gdSheet['sheets'][0]['properties']['title']
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

        $this->client->deleteFile($gdSheet['spreadsheetId']);
    }

    public function testUpdateSheetValues()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic_1.csv',
            'titanic_1',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );
        $gdSheet = $this->client->getSpreadsheet($gdFile['id']);

        $values = $this->csvToArray($this->dataPath . '/titanic_2.csv');

        $response =$this->client->updateSpreadsheetValues(
            $gdFile['id'],
            $gdSheet['sheets'][0]['properties']['title'],
            $values
        );

        $this->assertArrayHasKey('spreadsheetId', $response);
        $this->assertArrayHasKey('updatedRange', $response);
        $this->assertArrayHasKey('updatedRows', $response);
        $this->assertArrayHasKey('updatedColumns', $response);
        $this->assertArrayHasKey('updatedCells', $response);

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);

        $gdValues = $this->client->getSpreadsheetValues(
            $response['spreadsheetId'],
            $response['updatedRange']
        );

        $this->assertEquals($values, $gdValues['values']);

        $this->client->deleteFile($gdSheet['spreadsheetId']);
    }

    public function testAppendSheetValues()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );
        $gdSheet = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->csvToArray($this->dataPath . '/titanic_2.csv');
        array_shift($values); // skip header

        $response =$this->client->appendSpreadsheetValues(
            $gdFile['id'],
            $gdSheet['sheets'][0]['properties']['title'],
            $values
        );

        $expectedValues = $this->csvToArray($this->dataPath . '/titanic.csv');
        $gdValues = $this->client->getSpreadsheetValues(
            $response['spreadsheetId'],
            $gdSheet['sheets'][0]['properties']['title']
        );
        $this->assertEquals($expectedValues, $gdValues['values']);

        $this->client->deleteFile($gdSheet['spreadsheetId']);
    }

    public function testClearSheetValues()
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );
        $gdSheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetTitle = $gdSheet['sheets'][0]['properties']['title'];

        $this->client->clearSpreadsheetValues($gdFile['id'], $sheetTitle);
        $values = $this->client->getSpreadsheetValues($gdFile['id'], $sheetTitle);

        $this->assertArrayNotHasKey('values', $values);
    }

    public function testCreateFileInTeamFolder()
    {
        $this->client->setTeamDriveSupport(true);
        $folderId = getenv('GOOGLE_DRIVE_TEAM_FOLDER');
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [$folderId]
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

    public function testGetTeamFile()
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_TEAM_FOLDER')]
            ]
        );
        $file = $this->client->getFile($gdFile['id']);

        $this->assertArrayHasKey('id', $file);
        $this->assertArrayHasKey('name', $file);
        $this->assertArrayHasKey('parents', $file);
        $this->assertEquals('titanic', $file['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateTeamFile()
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                getenv('GOOGLE_DRIVE_TEAM_FOLDER')
            ]
    );
        $res = $this->client->updateFile($gdFile['id'], $this->dataPath . '/titanic.csv', [
            'name' => $gdFile['name'] . '_changed'
        ]);

        $this->assertArrayHasKey('id', $res);
        $this->assertArrayHasKey('name', $res);
        $this->assertArrayHasKey('kind', $res);
        $this->assertArrayHasKey('parents', $res);
        $this->assertEquals($gdFile['id'], $res['id']);
        $this->assertEquals($gdFile['name'] . '_changed', $res['name']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testDeleteTeamFile()
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                getenv('GOOGLE_DRIVE_TEAM_FOLDER')
            ]
        );
        $this->client->deleteFile($gdFile['id']);

        $this->expectException('GuzzleHttp\\Exception\\ClientException');
        $this->client->getFile($gdFile['id']);
    }

    protected function csvToArray($pathname)
    {
        return array_map('str_getcsv', file($pathname));
    }
}
