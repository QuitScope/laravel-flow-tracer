<?php

namespace LaravelFlowTracer\Tests\Feature;

use Illuminate\Support\Facades\Route;
use LaravelFlowTracer\Tests\TestCase;

class FlowTraceCommandTest extends TestCase
{
    /** @test */
    public function it_can_run_flow_trace_command()
    {
        $this->artisan('flow:trace --help')
             ->assertExitCode(0);
    }

    /** @test */
    public function it_registers_flow_trace_command()
    {
        $commands = $this->app['Illuminate\Contracts\Console\Kernel']->all();
        
        $this->assertArrayHasKey('flow:trace', $commands);
    }

    /** @test */
    public function it_returns_json_for_a_named_route_without_generating_an_image()
    {
        Route::get('/flow-tracer-test', [FlowTraceTestController::class, 'show'])
            ->name('flow-tracer.test');

        $this->artisan('flow:trace', [
            '--route' => 'flow-tracer.test',
            '--format' => 'json',
            '--no-png' => true,
        ])
            ->expectsOutputToContain('"start_point": "flow-tracer.test"')
            ->expectsOutputToContain('"controller": "LaravelFlowTracer\\\\Tests\\\\Feature\\\\FlowTraceTestController"')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_rejects_an_unsupported_output_format()
    {
        $this->artisan('flow:trace', ['--format' => 'xml'])
            ->expectsOutput('Unsupported output format: xml. Supported formats: table, json')
            ->assertExitCode(1);
    }
}

class FlowTraceTestController
{
    public function show(): array
    {
        return ['status' => 'ok'];
    }
}
