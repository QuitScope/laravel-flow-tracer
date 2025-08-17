<?php

namespace LaravelFlowTracer\Tests\Feature;

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
}