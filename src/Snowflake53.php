<?php

namespace Snowflake53;

use Exception;
use SysvSharedMemory;
use SysvSemaphore;

/* snowflake ID generator, 53 bits variant, use s/10, 255 machine, 1023 sequences  
 * up for more than 100 years (from 2024 to 2124, then javascript might support
 * full 64 bits integers)
 * Can also generate 63 bits IDs if needed.
 * 
 * Counter is shared between all processes on the same machine via shared memory
 * so it doesn't need to have Redis, Memcached or any other shared storage.
 */
trait ID {
    /* ms since 1st march 2024 at 9:00:00 */
    const EPOCH63 = 1709280000000;
    const PRJ63 = '6';
    const SEQ63 = 0xFFF;
    const MACHINE63 = 0x3FF;

    /* 10th of seconds since 1st march 2024 at 9:00:00 */
    CONST EPOCH53 =   17092800000;
    const PRJ53 = '5';
    const MACHINE53 = 0xFF;
    const SEQ53 = 0x3FF;

    public static string $SHMPath = __FILE__;
    private static ?int $machineId = null;
    /**
     * Initialize shared memory and semaphore. It is used to count sequences.
     * @param string $prj Either '5' or '6'.
     * @return (SysvSharedMemory|SysvSemaphore)[] Semaphore and shared memory segment.
     * @throws Exception 
     */
    private static function initSHM (string $prj = self::PRJ53):array {
        $shmId = ftok(self::$SHMPath, $prj);
        if ($shmId === -1) {
            throw new Exception('Error creating shared memory segment');
        }

        $shm = shm_attach($shmId, 1024, 0666);
        if ($shm === false) {
            throw new Exception('Error attaching shared memory segment');
        }

        $sem = sem_get($shmId, 1, 0666);
        if ($sem === false) {
            throw new Exception('Error creating semaphore');
        }

        $maxTries = 100;
        do {
            $maxTries--;
            if (sem_acquire($sem, true)) {
                break;
            }
            if ($maxTries <= 0) {
                throw new Exception('Error acquiring semaphore');
            }
            usleep(100);
        } while (1);

        return [$shm, $sem];
    }

    /**
     * Destroy shared memory and semaphore.
     * @return void 
     * @throws Exception 
     */
    public static function destroySHM ():void {
        foreach ([self::PRJ53, self::PRJ63] as $prj) {
            try {
                [$shm, $sem] = self::initSHM($prj);
                self::updateSequenceId($shm, 0);
                sem_remove($sem);
                shm_remove($shm);
            } catch (Exception $e) {
                // nothing. already gone
            }
        }
    }

    /**
     * Get sequence ID from shared memory.
     * @param mixed $shm Shared memory handle.
     * @param int $seq Either SEQ53 or SEQ64.
     * @return mixed 
     */
    private static function getSequenceId (SysvSharedMemory $shm, int $seq = self::SEQ53):int {
        $sequenceId = 0;
        if (shm_has_var($shm, 0)) {
            $sequenceId = shm_get_var($shm, 0);
        }
        if ($sequenceId > $seq) {
            $sequenceId = 0;
        }
        return $sequenceId;
    }

    private static function updateSequenceId (SysvSharedMemory $shm, int $sequenceId):void {
        if (!shm_put_var($shm, 0, $sequenceId + 1)) {
            throw new Exception('Error updating sequence ID');
        }
    }

    private static function releaseSHM (SysvSharedMemory $shm, $sem):void {
        sem_release($sem);
        shm_detach($shm);
    }

    private static function getLastTime (SysvSharedMemory $shm):int {
        $lastTime = 0;
        if (shm_has_var($shm, 1)) {
            $lastTime = shm_get_var($shm, 1);
        }
        return $lastTime;
    }

    private static function updateLastTime (SysvSharedMemory $shm, int $time):void {
        if (!shm_put_var($shm, 1, $time)) {
            throw new Exception('Error updating last time');
        }
    }

    /**
     * Get machine ID from environment variables.
     * For 53 bits variant, it will look, in order, for SNOWFLAKE53_MACHINE_ID
     * and SNOWFLAKE_MACHINE_ID. For 64 bits variant, it will look for 
     * SNOWFLAKE64_MACHINE_ID and SNOWFLAKE_MACHINE_ID.
     * So machine ID can be set differently for 53 and 64 bits variants.
     * @param int $mask Either MACHINE53 or MACHINE64
     * @return int masked, by $mask, integer.
     */
    private static function getMachineId (int $mask = self::MACHINE53):int {
        if (self::$machineId !== null) return self::$machineId;
        $machineId = 0;
        switch ($mask) {
            default:
            case self::MACHINE53:
                $machineId = getenv('SNOWFLAK53_MACHINE_ID');
                break;
            case self::MACHINE63:
                $machineId = getenv('SNOWFLAK64_MACHINE_ID');
                break;
        }
        if ($machineId === false) {
            $machineId = getenv('SNOWFLAKE_MACHINE_ID');
        }
        self::$machineId = (intval($machineId) & $mask);
        return self::$machineId;
    }

    /**
     * Generate a 53 bits ID.
     * @param int $machineId The machine ID, if set to -1, it will look for SNOWFLAKE53_MACHINE_ID, SNOWFLAKE64_MACHINE_ID
     * and SNOWFLAKE_MACHINE_ID environment variables. If not found, it will use 0.
     * @return int A 53 bits integer.
     * @throws Exception 
     */
    public static function get53(int $machineId = -1):int {
        try {
            list($shm, $sem) = self::initSHM(self::PRJ53);

            $time = (intval((microtime(true)) * 10) - self::EPOCH53) & 0x7FFFFFFFF;            
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm, self::SEQ53);
            }
            self::updateLastTime($shm, $time);
            if ($time === $lastTime) {
                self::updateSequenceId($shm, $sequenceId);
            } else {
                self::updateSequenceId($shm, 0);
            }
            self::releaseSHM($shm, $sem);
            $machineId = (
                $machineId < 0 
                    ? self::getMachineId(self::MACHINE53) 
                    : $machineId
            );
            return 0 
                | ($time << 18) 
                | (($machineId & self::MACHINE53) << 10)  
                | ($sequenceId & self::SEQ53);
        } catch (Exception $e) {
            throw new Exception('Error generating ID', 0, $e);
        }
    }

    /**
     * @see get53
     * @deprecated 
     */
    public static function generateId (int $machineId = -1):int {
        return self::get53($machineId);
    }

    /**
     * @see get63
     * @deprecated 
     */
    public static function get64(int $machineId = -1):int {
        return self::get63($machineId);
    }

    /**
     * Generate a 63 bits ID.
     * @param int $machineId The machine ID, if set to -1, it will look for SNOWFLAKE64_MACHINE_ID, SNOWFLAKE53_MACHINE_ID
     * and SNOWFLAKE_MACHINE_ID environment variables. If not found, it will use 0.
     * @return int A 63 bits integer.
     * @throws Exception 
     */
    public static function get63(int $machineId = -1):int {
        try {
            list($shm, $sem) = self::initSHM(self::PRJ63);
            $time = (int) (microtime(true) * 1000) - self::EPOCH63;
            if ($time < 0 || $time >= (1 << 41)) {
                throw new Exception('Timestamp not in the 41 bit range');
            }
                        
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm, self::SEQ63);
            }

            self::updateLastTime($shm, $time);
            if ($time === $lastTime) {
                self::updateSequenceId($shm, $sequenceId);
            } else {
                self::updateSequenceId($shm, 0);
            }
            self::releaseSHM($shm, $sem);
            $machineId = (
                $machineId < 0 
                    ? self::getMachineId(self::MACHINE63) 
                    : $machineId
            );

            $id = 0 
                | ($time << 22) 
                | (($machineId & self::MACHINE63) << 12)  
                | ($sequenceId & self::SEQ63);
            
            if ($id > PHP_INT_MAX || $id < 0) {
                throw new Exception('ID not in range');
            }
            return $id;
        } catch (Exception $e) {
            throw new Exception('Error generating ID', 0, $e);
        }
    }

    /**
     * @see get64
     * @deprecated 
     */
    public static function generateId64 (int $machineId = -1):int {
        return self::get63($machineId);
    }
}