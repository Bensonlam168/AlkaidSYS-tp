<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use Domain\Event\EventService;
use think\facade\Event;

class TestEvent extends Command
{
    protected function configure()
    {
        // Command name
        $this->setName('test:event')
            ->setDescription('Test EventService functionality');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new EventService();
        $output->writeln("Testing EventService...");

        // 1. Test Priority Listener
        $output->writeln("Testing listenWithPriority...");
        
        // Note: Since our simple implementation just wraps Event::listen, 
        // we verify it registers without error. 
        // Real priority verification would require mocking the Event dispatcher internals 
        // or observing execution order of multiple listeners.
        
        $service->listenWithPriority('test.priority', function() use ($output) {
            $output->writeln("Priority listener executed.");
        }, 10);
        
        Event::trigger('test.priority');
        
        // 2. Test Async Event
        $output->writeln("Testing triggerAsync...");
        
        // Register a listener for the async event
        Event::listen('test.async', function($params) use ($output) {
            $data = $params['data'] ?? 'unknown';
            $output->writeln("Async listener executed with data: {$data}");
        });
        
        // Trigger async
        // Since queue driver is 'sync' by default in dev, this should run immediately.
        $service->triggerAsync('test.async', ['data' => 'hello world']);
        
        $output->writeln("Test complete.");
    }
}
