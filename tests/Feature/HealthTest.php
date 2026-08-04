<?php

it('reports healthy when database and redis are reachable', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk();
    $response->assertJson([
        'status' => 'ok',
        'checks' => [
            'database' => true,
            'redis' => true,
        ],
    ]);
});
