<?php

function response(bool $success, $result)
{
    $response = [
        'success' => $success,
        'result' => $result,
    ];

    header("Content-type: application/json");
    echo json_encode($response, JSON_THROW_ON_ERROR);
    exit;
}

function forbidden(): void
{
    header('HTTP/1.0 403 Forbidden');
    die('You are forbidden!');
}

function validation_errors(array $errors)
{
    $response = [
        'success' => false,
        'message' => 'Invalid data',
        'errors' => $errors,
    ];

    header("Content-type: application/json");
    echo json_encode($response, JSON_THROW_ON_ERROR);
    header('HTTP/1.0 422 Unprocessable entity');
    exit;
}
