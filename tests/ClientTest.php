<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsClient\Tests;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ClientTest extends TestCase
{
    protected string $dataPath = __DIR__ . '/data';

    protected Client $client;

    public function setUp(): void
    {
        $api = RestApi::createWithOAuth(
            (string) getenv('CLIENT_ID'),
            (string) getenv('CLIENT_SECRET'),
            (string) getenv('ACCESS_TOKEN'),
            (string) getenv('REFRESH_TOKEN'),
        );
        $api->setBackoffsCount(2); // Speeds up the tests
        $this->client = new Client($api);
    }

    public function testGenerateIds(): void
    {
        $ids = $this->client->generateIds();
        $this->assertNotEmpty($ids);
        $idsArray = $this->assertArrayKeyIsArray('ids', $ids);
        $this->assertCount(10, $idsArray);
    }

    public function testFileExists(): void
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
        );
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $exists = $this->client->fileExists($fileId);
        $this->assertTrue($exists);

        $this->client->deleteFile($fileId);
    }

    public function testCreateFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('name', $gdFile));
        $this->assertEquals('drive#file', $this->assertArrayKeyIsString('kind', $gdFile));

        $this->client->deleteFile($fileId);
    }

    public function testCreateFileWithConversion(): void
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ],
        );
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('name', $gdFile));
        $this->assertEquals('drive#file', $this->assertArrayKeyIsString('kind', $gdFile));

        $fileMeta = $this->client->getFile($fileId);
        self::assertEquals(
            'application/vnd.google-apps.spreadsheet',
            $this->assertArrayKeyIsString('mimeType', $fileMeta),
        );

        $this->client->deleteFile($fileId);
    }

    public function testCreateFileInFolder(): void
    {
        $folderId = getenv('GOOGLE_DRIVE_FOLDER');
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [$folderId],
            ],
        );

        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $gdFile = $this->client->getFile($fileId);
        $parents = $this->assertArrayKeyIsArray('parents', $gdFile);
        $this->assertContains($folderId, $parents);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('name', $gdFile));

        $this->client->deleteFile($fileId);
    }

    public function testGetFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $file = $this->client->getFile($fileId);

        $this->assertArrayKeyIsString('id', $file);
        $this->assertArrayKeyIsArray('parents', $file);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('name', $file));

        $this->client->deleteFile($fileId);
    }

    public function testUpdateFile(): void
    {
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic_1.csv',
            'titanic',
            [
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ],
        );
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $fileName = $this->assertArrayKeyIsString('name', $gdFile);
        $res = $this->client->updateFile($fileId, $this->dataPath . '/titanic_2.csv', [
            'name' => $fileName . '_changed',
        ]);

        $resId = $this->assertArrayKeyIsString('id', $res);
        $this->assertArrayKeyIsString('kind', $res);
        $this->assertArrayKeyIsArray('parents', $res);
        $this->assertEquals($fileId, $resId);
        $this->assertEquals($fileName . '_changed', $this->assertArrayKeyIsString('name', $res));

        $spreadsheet = $this->client->getSpreadsheet($resId);
        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $gdValues = $this->client->getSpreadsheetValues(
            $spreadsheetId,
            'titanic',
        );

        $expectedValues = $this->csvToArray($this->dataPath . '/titanic_2.csv');
        $this->assertEquals($expectedValues, $this->assertArrayKeyIsArray('values', $gdValues));

        $this->client->deleteFile($fileId);
    }

    public function testDeleteFile(): void
    {
        $gdFile = $this->client->createFile($this->dataPath . '/titanic.csv', 'titanic');
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $this->client->deleteFile($fileId);

        $this->expectException('GuzzleHttp\\Exception\\ClientException');
        $this->client->getFile($fileId);
    }

    public function testCreateSheet(): void
    {
        $res = $this->client->createSpreadsheet(
            ['title' => 'titanic'],
            ['properties' => ['title' => 'my_test_sheet']],
        );

        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $res);
        $properties = $this->assertArrayKeyIsArray('properties', $res);
        $sheets = $this->assertArrayKeyIsArray('sheets', $res);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('title', $properties));
        $this->assertCount(1, $sheets);
        $sheet = array_shift($sheets);
        $this->assertIsArray($sheet);
        /** @var array<mixed> $sheet */
        $sheetProperties = $this->assertArrayKeyIsArray('properties', $sheet);
        $this->assertArrayKeyIsString('title', $sheetProperties);
        $this->assertEquals('my_test_sheet', $this->assertArrayKeyIsString('title', $sheetProperties));

        $this->client->deleteFile($spreadsheetId);
    }

    public function testAddSheet(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ],
        );
        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $res = $this->client->addSheet($spreadsheetId, [
            'properties' => ['title' => 'sheet_2'],
        ]);

        $this->assertArrayKeyIsString('spreadsheetId', $res);
        $this->assertArrayKeyIsArray('replies', $res);

        $res2 = $this->client->getSpreadsheet($spreadsheetId);
        $this->assertCount(2, $this->assertArrayKeyIsArray('sheets', $res2));

        $this->client->deleteFile($spreadsheetId);
    }

    public function testGetSheet(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ],
        );
        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $spreadsheet = $this->client->getSpreadsheet($spreadsheetId);

        $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $this->assertArrayKeyIsArray('properties', $spreadsheet);
        $this->assertArrayKeyIsArray('sheets', $spreadsheet);

        $this->client->deleteFile($spreadsheetId);
    }

    public function testGetSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ],
        );
        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $this->client->updateSpreadsheetValues(
            $spreadsheetId,
            'sheet_1',
            $this->csvToArray($this->dataPath . '/titanic.csv'),
        );

        $sheets = $this->assertArrayKeyIsArray('sheets', $spreadsheet);
        $this->assertIsArray($sheets[0]);
        /** @var array<mixed> $firstSheet */
        $firstSheet = $sheets[0];
        $sheetProperties = $this->assertArrayKeyIsArray('properties', $firstSheet);
        $sheetTitle = $this->assertArrayKeyIsString('title', $sheetProperties);

        $response = $this->client->getSpreadsheetValues(
            $spreadsheetId,
            $sheetTitle,
        );

        $this->assertArrayKeyIsString('range', $response);
        $this->assertArrayKeyIsString('majorDimension', $response);
        $values = $this->assertArrayKeyIsArray('values', $response);
        $this->assertIsArray($values[0]);
        /** @var array<mixed> $header */
        $header = $values[0];
        $this->assertEquals('Class', $header[1]);
        $this->assertEquals('Sex', $header[2]);
        $this->assertEquals('Age', $header[3]);
        $this->assertEquals('Survived', $header[4]);
        $this->assertEquals('Freq', $header[5]);

        $this->client->deleteFile($spreadsheetId);
    }

    public function testUpdateSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ],
        );

        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $sheets = $this->assertArrayKeyIsArray('sheets', $spreadsheet);
        $this->assertIsArray($sheets[0]);
        /** @var array<mixed> $firstSheet */
        $firstSheet = $sheets[0];
        $sheetProperties = $this->assertArrayKeyIsArray('properties', $firstSheet);
        $sheetTitle = $this->assertArrayKeyIsString('title', $sheetProperties);

        $values = $this->csvToArray($this->dataPath . '/titanic_2.csv');

        $response =$this->client->updateSpreadsheetValues(
            $spreadsheetId,
            $sheetTitle,
            $values,
        );

        $responseSpreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $response);
        $updatedRange = $this->assertArrayKeyIsString('updatedRange', $response);
        $this->assertArrayHasKey('updatedRows', $response);
        $this->assertArrayHasKey('updatedColumns', $response);
        $this->assertArrayHasKey('updatedCells', $response);

        $this->assertEquals($spreadsheetId, $responseSpreadsheetId);

        $gdValues = $this->client->getSpreadsheetValues(
            $responseSpreadsheetId,
            $updatedRange,
        );

        $this->assertEquals($values, $this->assertArrayKeyIsArray('values', $gdValues));

        $this->client->deleteFile($spreadsheetId);
    }

    public function testAppendSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ],
        );
        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $this->client->updateSpreadsheetValues(
            $spreadsheetId,
            'sheet_1',
            $this->csvToArray($this->dataPath . '/titanic_1.csv'),
        );

        $sheets = $this->assertArrayKeyIsArray('sheets', $spreadsheet);
        $this->assertIsArray($sheets[0]);
        /** @var array<mixed> $firstSheet */
        $firstSheet = $sheets[0];
        $sheetProperties = $this->assertArrayKeyIsArray('properties', $firstSheet);
        $sheetTitle = $this->assertArrayKeyIsString('title', $sheetProperties);

        $values = $this->csvToArray($this->dataPath . '/titanic_2.csv');
        array_shift($values); // skip header

        $response =$this->client->appendSpreadsheetValues(
            $spreadsheetId,
            $sheetTitle,
            $values,
        );

        $expectedValues = $this->csvToArray($this->dataPath . '/titanic.csv');
        $responseSpreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $response);
        $gdValues = $this->client->getSpreadsheetValues(
            $responseSpreadsheetId,
            $sheetTitle,
        );
        $this->assertEquals($expectedValues, $this->assertArrayKeyIsArray('values', $gdValues));

        $this->client->deleteFile($spreadsheetId);
    }

    public function testClearSheetValues(): void
    {
        $spreadsheet = $this->client->createSpreadsheet(
            [
                'title' => 'titanic',
            ],
            [
                'properties' => ['title' => 'sheet_1'],
            ],
        );
        $spreadsheetId = $this->assertArrayKeyIsString('spreadsheetId', $spreadsheet);
        $this->client->updateSpreadsheetValues(
            $spreadsheetId,
            'sheet_1',
            $this->csvToArray($this->dataPath . '/titanic.csv'),
        );
        $sheets = $this->assertArrayKeyIsArray('sheets', $spreadsheet);
        $this->assertIsArray($sheets[0]);
        /** @var array<mixed> $firstSheet */
        $firstSheet = $sheets[0];
        $sheetProperties = $this->assertArrayKeyIsArray('properties', $firstSheet);
        $sheetTitle = $this->assertArrayKeyIsString('title', $sheetProperties);

        $this->client->clearSpreadsheetValues($spreadsheetId, $sheetTitle);
        $values = $this->client->getSpreadsheetValues($spreadsheetId, $sheetTitle);

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
            ],
        );

        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $gdFile = $this->client->getFile($fileId);
        $this->assertArrayKeyIsString('id', $gdFile);
        $parents = $this->assertArrayKeyIsArray('parents', $gdFile);
        $this->assertContains($folderId, $parents);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('name', $gdFile));

        $this->client->deleteFile($fileId);
    }

    public function testGetTeamFile(): void
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_TEAM_FOLDER')],
            ],
        );
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $file = $this->client->getFile($fileId);

        $this->assertArrayKeyIsString('id', $file);
        $this->assertArrayKeyIsArray('parents', $file);
        $this->assertEquals('titanic', $this->assertArrayKeyIsString('name', $file));

        $this->client->deleteFile($fileId);
    }

    public function testUpdateTeamFile(): void
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_TEAM_FOLDER')],
            ],
        );
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $fileName = $this->assertArrayKeyIsString('name', $gdFile);
        $res = $this->client->updateFile($fileId, $this->dataPath . '/titanic.csv', [
            'name' => $fileName . '_changed',
        ]);

        $resId = $this->assertArrayKeyIsString('id', $res);
        $this->assertArrayKeyIsString('kind', $res);
        $this->assertArrayKeyIsArray('parents', $res);
        $this->assertEquals($fileId, $resId);
        $this->assertEquals($fileName . '_changed', $this->assertArrayKeyIsString('name', $res));

        $this->client->deleteFile($fileId);
    }

    public function testDeleteTeamFile(): void
    {
        $this->client->setTeamDriveSupport(true);
        $gdFile = $this->client->createFile(
            $this->dataPath . '/titanic.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_TEAM_FOLDER')],
            ],
        );
        $fileId = $this->assertArrayKeyIsString('id', $gdFile);
        $this->client->deleteFile($fileId);

        $this->expectException('GuzzleHttp\\Exception\\ClientException');
        $this->client->getFile($fileId);
    }

    /**
     * @return array<array<string>>
     */
    protected function csvToArray(string $pathname): array
    {
        $lines = file($pathname);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Failed to read file: %s', $pathname));
        }
        $result = array_map('str_getcsv', $lines);
        /** @var array<array<string>> $result */
        return $result;
    }

    /**
     * @param array<mixed> $array
     */
    private function assertArrayKeyIsString(string $key, array $array): string
    {
        $this->assertArrayHasKey($key, $array);
        $this->assertIsString($array[$key]);
        /** @var string */
        return $array[$key];
    }

    /**
     * @param array<mixed> $array
     * @return array<mixed>
     */
    private function assertArrayKeyIsArray(string $key, array $array): array
    {
        $this->assertArrayHasKey($key, $array);
        $this->assertIsArray($array[$key]);
        /** @var array<mixed> */
        return $array[$key];
    }
}
