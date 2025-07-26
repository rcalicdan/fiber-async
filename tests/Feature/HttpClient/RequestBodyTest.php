<?php

beforeEach(function () {
    resetEventLoop();
});

describe('HTTP Client Request Body', function () {

    test('json() method sends correct body and headers', function () {
        $data = ['name' => 'Fiber', 'type' => 'Async'];
        $response = run(fn() => await(http()->json($data)->post('https://httpbin.org/post')));

        $json = $response->json();
        expect($json['json'])->toEqual($data);
        expect($json['headers']['Content-Type'])->toBe('application/json');
    });

    test('form() method sends correct body and headers', function () {
        $data = ['framework' => 'fiber-async', 'version' => '1.0'];
        $response = run(fn() => await(http()->form($data)->post('https://httpbin.org/post')));

        $json = $response->json();
        expect($json['form'])->toEqual($data);
        expect($json['headers']['Content-Type'])->toBe('application/x-www-form-urlencoded');
    });

    test('body() method sends raw string data', function () {
        $xml = '<test><name>MyXML</name></test>';
        $response = run(fn() => await(
            http()
                ->contentType('application/xml')
                ->body($xml)
                ->post('https://httpbin.org/post')
        ));

        $json = $response->json();
        expect($json['data'])->toBe($xml);
        expect($json['headers']['Content-Type'])->toBe('application/xml');
    });

    test('multipart() method sends files and data', function () {
        $filePath = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($filePath, 'Hello World');

        $cURLFile = new \CURLFile($filePath, 'text/plain', 'test-file.txt');

        $postData = [
            'text_field'  => 'some text',
            'file_upload' => $cURLFile
        ];

        $response = run(fn() => await(
            http()
                ->multipart($postData) 
                ->post('https://httpbin.org/post')
        ));

        unlink($filePath);

        $json = $response->json();
        expect($response->ok())->toBeTrue();
        expect($json['form'])->toEqual(['text_field' => 'some text']);
        expect($json['files'])->toEqual(['file_upload' => 'Hello World']);
        expect($json['headers']['Content-Type'])->toStartWith('multipart/form-data');
    });
});