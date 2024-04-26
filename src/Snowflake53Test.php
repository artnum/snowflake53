<?php
require '../vendor/autoload.php';

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

    public function testMixValues64()
    {
        $time = 1234567890;
        $machineId = 42;
        $sequenceId = 7;

        $expected = 0
            | ($time << 23)
            | (($machineId & Snowflake53\MACHINE64) << 13)
            | ($sequenceId & Snowflake53\SEQ64);

        $mixValues64 = self::getMethod('mixValues64');
        $sn53 = new SN53();
        $result = $mixValues64->invokeArgs($sn53, [$time, $machineId, $sequenceId]);

        $this->assertEquals($expected, $result);
    }

    public function testMixValues53()
    {
        $time = 1234567890;
        $machineId = 42;
        $sequenceId = 7;

        $expected = 0
            | ($time << 18)
            | (($machineId & Snowflake53\MACHINE53) << 10)
            | ($sequenceId & Snowflake53\SEQ53);

        $mixValues53 = self::getMethod('mixValues53');
        $sn53 = new SN53();
        $result = $mixValues53->invokeArgs($sn53, [$time, $machineId, $sequenceId]);

        $this->assertEquals($expected, $result);
    }

    public function testSharedMemory () 
    {
        $initSHM = self::getMethod('initSHM');
        $destroySHM = self::getMethod('destroySHM');
        $sn53 = new SN53();
        $sn53::$SHMPath = __FILE__;
        try {
            $initSHM->invokeArgs($sn53, [Snowflake53\PRJ53]);
            $destroySHM->invokeArgs($sn53, [Snowflake53\PRJ53]);
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
            $result = SN53::get64($machineId);
        }

        if ($machineId < 0) { $machineId = 0; }
        $this->assertEquals($machineId, ($result >> 13) & 0x3FF);

        SN53::destroySHM();

        $lastTime = 0;
        $value = 0;
        for ($i = 0; $i < 100; $i++) {
            $result = SN53::get64($machineId);
            $time = $result >> 23;
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
            $result = SN53::get64($machineId);
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
            $result = SN53::get64();
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

}