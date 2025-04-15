<?php
require __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class SN53 { use Snowflake53\ID; }

class Snowflake53Test extends TestCase
{
    protected static function getMethod ($name) {
        $class = new ReflectionClass('SN53');
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method;
    }

    public function testSharedMemory () 
    {
        $initSHM = self::getMethod('initSHM');
        $destroySHM = self::getMethod('destroySHM');
        $sn53 = new SN53();
        $sn53::$SHMPath = __FILE__;
        try {
            $initSHM->invokeArgs($sn53, [SN53::PRJ53]);
            $destroySHM->invokeArgs($sn53, [SN53::PRJ53]);
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }
        $this->assertTrue(TRUE);        
    }

    private function genId64 ($machineId) {
        SN53::$SHMPath = __FILE__;
        $result = 0;
        /* generate a set of ID to check destroySHM */
        for ($i = 0; $i < 100; $i++) {
            $result = SN53::get63($machineId);
        }

        if ($machineId < 0) { $machineId = 0; }
        $this->assertEquals($machineId, ($result >> 12) & 0x3FF);

        SN53::destroySHM();

        $lastTime = 0;
        $value = 0;
        for ($i = 0; $i < 100; $i++) {
            $result = SN53::get63($machineId);
            $time = $result >> 22;
            if ($time !== $lastTime) {
                $lastTime = $time;
                $value = 0;
            }
            $this->assertEquals($value, $result & 0xFFF);
            $value++;
        }
    }

    private function genId53 ($machineId) {
        SN53::$SHMPath = __FILE__;
        $result = 0;
        /* generate a set of ID to check destroySHM */
        for ($i = 0; $i < 100; $i++) {
            $result = SN53::get53($machineId);
        }
        if ($machineId < 0) { $machineId = 0; }

        $this->assertEquals($machineId, ($result >> 10) & 0xFF);

        SN53::destroySHM();

        $lastTime = 0;
        $value = 0;
        for ($i = 0; $i < 100; $i++) {
            $result = SN53::get63($machineId);
            $time = $result >> 18;
            if ($time !== $lastTime) {
                $lastTime = $time;
                $value = 0;
            }
            $this->assertEquals($value, $result & 0x3FF);
            $value++;
        }
    }

    public function testGet64Unicity () {
        SN53::$SHMPath = __FILE__;
        SN53::destroySHM();
        $array = [];

        for ($i = 0; $i < 50000; $i++) {
            $result = SN53::get63();
            $this->assertArrayNotHasKey($result, $array);
            $array[$result] = 1;
        }

    }

    public function testGet64WithDefaultMachineId()
    {
        $this->genId64(-1);
    }

    public function testGet64WithCustomMachineId()
    {
        $this->genId64(0x3FF);
    }


    public function testGet53Unicity () {
        SN53::$SHMPath = __FILE__;
        SN53::destroySHM();
        $array = [];

        for ($i = 0; $i < 1023; $i++) {
            $result = SN53::get53();
            $this->assertArrayNotHasKey($result, $array);
            $array[$result] = 1;
        }
    }

    public function testGet53WithDefaultMachineId()
    {
        $this->genId53(-1);
    }

    public function testGet53WithCustomMachineId()
    {
        $this->genId53(0xFF);
    }

    public function testGet53InvalidMachineId(): void
    {
        $id = SN53::get53(0x100); // Beyond 8 bits
        $machineId = ($id >> 10) & SN53::MACHINE53;
        $this->assertEquals(0, $machineId); // Should be masked to 0xFF
    }

    public function testGet63InvalidMachineId(): void
    {
        $id = SN53::get63(0x400); // Beyond 10 bits
        $machineId = ($id >> 12) & SN53::MACHINE63;
        $this->assertEquals(0, $machineId); // Should be masked to 0x3FF
    }

    public function testGet63ConcurrentUniqueness(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required');
        }

        SN53::destroySHM();
        SN53::$SHMPath = __FILE__;

        $pids = [];
        $numProcesses = 50;
        $idsPerProcess = 10000;
        $sharedFile = sys_get_temp_dir() . '/snowflake_ids.txt';

        // Fork multiple processes
        for ($i = 0; $i < $numProcesses; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                $ids = [];
                for ($j = 0; $j < $idsPerProcess; $j++) {
                    $ids[] = SN53::get63();
                }
                file_put_contents($sharedFile, implode("\n", $ids) . "\n", FILE_APPEND);
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        // Wait for all processes to complete
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Read and check IDs
        $allIds = array_map('intval', array_filter(explode("\n", file_get_contents($sharedFile))));
        $uniqueIds = array_unique($allIds);
        unlink($sharedFile);
        $this->assertEquals(count($allIds), count($uniqueIds), 'Duplicate IDs found in concurrent generation');
    }

    public function testGet63TimestampAccuracy(): void
    {
        $id = SN53::get63();
        $timestamp = ($id >> 22) & 0x1FFFFFFFFFF;
        $expectedTime = (int)((microtime(true) * 1000) - SN53::EPOCH63);
        $this->assertLessThanOrEqual(1, abs($timestamp - $expectedTime), 'Timestamp in ID does not match current time');
    }

    public function testGet53TimestampAccuracy(): void
    {
        $id = SN53::get53();
        $timestamp = ($id >> 18) & 0x7FFFFFFFF;
        $expectedTime = (int)((microtime(true) * 10) - SN53::EPOCH53);
        $this->assertLessThanOrEqual(1, abs($timestamp - $expectedTime), 'Timestamp in ID does not match current time');
    }

    /* after some testing I get below 2.2 sec regulary, so let set the test
     * at 2.5 sec for 100'000 IDs.
     */
    public function testGet53Performance(): void
    {
        SN53::destroySHM();
        $start = microtime(true);
        $numIds = 100000;
        for ($i = 0; $i < $numIds; $i++) {
            SN53::get53();
        }
        $duration = microtime(true) - $start;
        $this->assertLessThan(2.2, $duration, "Generating $numIds IDs took too long: $duration seconds");
    }

    public function testGet63Performance(): void
    {
        SN53::destroySHM();
        $start = microtime(true);
        $numIds = 100000;
        for ($i = 0; $i < $numIds; $i++) {
            SN53::get63();
        }
        $duration = microtime(true) - $start;
        $this->assertLessThan(2.2, $duration, "Generating $numIds IDs took too long: $duration seconds");
    }
}