<?php

namespace LaravelFlowTracer\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use LaravelFlowTracer\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FlowTraceCommandTest extends TestCase
{
    #[Test]
    public function it_can_run_flow_trace_command()
    {
        $this->artisan('flow:trace --help')
             ->assertExitCode(0);
    }

    #[Test]
    public function it_registers_flow_trace_command()
    {
        $commands = $this->app['Illuminate\Contracts\Console\Kernel']->all();
        
        $this->assertArrayHasKey('flow:trace', $commands);
    }

    #[Test]
    public function it_returns_json_for_a_named_route_without_generating_an_image()
    {
        Route::get('/flow-tracer-test', [FlowTraceTestController::class, 'show'])
            ->name('flow-tracer.test');
        Route::getRoutes()->refreshNameLookups();

        $exitCode = Artisan::call('flow:trace', [
            '--route' => 'flow-tracer.test',
            '--format' => 'json',
            '--no-png' => true,
        ]);
        $flow = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('flow-tracer.test', $flow['start_point']);
        $this->assertSame(FlowTraceTestController::class, $flow['controller']);
        $this->assertSame('show', $flow['action']);
    }

    #[Test]
    public function it_does_not_list_plain_method_calls_as_services()
    {
        Route::post('/flow-tracer-test', [FlowTraceTestController::class, 'store'])
            ->name('flow-tracer.store');
        Route::getRoutes()->refreshNameLookups();

        Artisan::call('flow:trace', [
            '--route' => 'flow-tracer.store',
            '--format' => 'json',
            '--no-png' => true,
        ]);
        $flow = json_decode(Artisan::output(), true);

        $types = array_column($flow['services'], 'type');
        $this->assertNotContains('Instance Method Call', $types);
        $this->assertNotContains('Property/Method Access', $types);
    }

    #[Test]
    public function it_rejects_an_unsupported_output_format()
    {
        $this->artisan('flow:trace', ['--format' => 'xml'])
            ->expectsOutput('Unsupported output format: xml. Supported formats: table, json')
            ->assertExitCode(1);
    }
}

class FlowTraceTestController
{
    public function __construct(private FlowTraceTestService $testService)
    {
    }

    public function show(): array
    {
        return ['status' => 'ok'];
    }

    public function store(\Illuminate\Http\Request $request): array
    {
        return $this->testService->save($request->all());
    }
}

class FlowTraceTestService
{
    public function save(array $data): array
    {
        return $data;
    }
}
