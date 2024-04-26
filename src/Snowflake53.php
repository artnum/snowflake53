<?php

namespace Snowflake53;

use Exception;
use SysvSharedMemory;
use SysvSemaphore;

  /* 10th of seconds since 1st march 2024 at 9:00:00 */
  CONST EPOCH53 =   17092800000;
  const EPOCH64 = 1709280000000;
  const PRJ53 = '5';
  const PRJ64 = '6';
  const MACHINE53 = 0xF;
  const MACHINE64 = 0x3FF;
  const SEQ53 = 0x3FF;
  const SEQ64 = 0xFFF;

/* snowflake ID generator, 53 bits variant, use s/10, 255 machine, 1023 sequences  
 * up for more than 100 years (from 2024 to 2124, then javascript might support
 * full 64 bits integers)
 * Can also generate 64 bits IDs if needed.
 * 
 * Counter is shared between all processes on the same machine via shared memory
 * so it doesn't need to have Redis, Memcached or any other shared storage.
 */
trait ID {
    public static string $SHMPath = __FILE__;
    /**
     * Initialize shared memory and semaphore. It is used to count sequences.
     * @param string $prj Either '5' or '6'.
     * @return (SysvSharedMemory|SysvSemaphore)[] Semaphore and shared memory segment.
     * @throws Exception 
     */
    private static function initSHM (string $prj = PRJ53):array {
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

        $maxTries = 1000;
        do {
            $maxTries--;
            if (sem_acquire($sem)) {
                break;
            }
            if ($maxTries <= 0) {
                throw new Exception('Error acquiring semaphore');
            }
            usleep(1000);
        } while (1);

        return [$shm, $sem];
    }

    /**
     * Destroy shared memory and semaphore.
     * @return void 
     * @throws Exception 
     */
    public static function destroySHM ():void {
        [$shm, $sem] = self::initSHM(PRJ53);
        sem_remove($sem);
        shm_remove($shm);
    }

    /**
     * Get sequence ID from shared memory.
     * @param mixed $shm Shared memory handle.
     * @param int $seq Either SEQ53 or SEQ64.
     * @return mixed 
     */
    private static function getSequenceId (SysvSharedMemory $shm, int $seq = SEQ53):int {
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
    private static function getMachineId (int $mask = MACHINE53):int {
        $machineId = 0;
        switch ($mask) {
            default:
            case MACHINE53:
                $machineId = getenv('SNOWFLAK53_MACHINE_ID')
                    || getenv('SNOWFLAKE_MACHINE_ID');
                break;
            case MACHINE64:
                $machineId = getenv('SNOWFLAK64_MACHINE_ID')
                    || getenv('SNOWFLAKE_MACHINE_ID');
                break;
        }
        if ($machineId === false) {
            return 0;
        }
        return intval($machineId) & $mask;
    }

    /**
     * Mix values to generate a 53 bits ID.
     * @param int $time The time
     * @param int $machineId The machine
     * @param int $sequenceId The sequence
     * @return int The ID
     */
    private static function mixValues53 (int $time, int $machineId, int $sequenceId):int {
        return 0 
            | ($time << 18) 
            | (($machineId & MACHINE53) << 10)  
            | ($sequenceId & SEQ53);
    }

    /**
     * Mix values to generate a 64 bits ID.
     * @param mixed $time The time
     * @param mixed $machineId The machine
     * @param mixed $sequenceId The sequence
     * @return int The ID
     */
    private static function mixValues64 (int $time, int $machineId, int $sequenceId):int {
        return 0 
            | ($time << 23) 
            | (($machineId & MACHINE64) << 13)  
            | ($sequenceId & SEQ64);
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
            $time = (intval(microtime(true) * 10) - EPOCH53) & 0x3FFFFFFFFFF;
            
            list($shm, $sem) = self::initSHM(PRJ53);
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm, SEQ53);
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
                    ? self::getMachineId(MACHINE53) 
                    : ($machineId & MACHINE53)
            );

            return self::mixValues53($time, $machineId, $sequenceId);
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
     * Generate a 64 bits ID.
     * @param int $machineId The machine ID, if set to -1, it will look for SNOWFLAKE64_MACHINE_ID, SNOWFLAKE53_MACHINE_ID
     * and SNOWFLAKE_MACHINE_ID environment variables. If not found, it will use 0.
     * @return int A 64 bits integer.
     * @throws Exception 
     */
    public static function get64(int $machineId = -1):int {
        try {
            $time = (intval(microtime(true) * 1000) - EPOCH64) & 0x7FFFFFFFF;
                        
            list($shm, $sem) = self::initSHM(PRJ64);
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm, SEQ64);
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
                    ? self::getMachineId(MACHINE64) 
                    : ($machineId & MACHINE64)
            );

            return self::mixValues64($time, $machineId, $sequenceId);
        } catch (Exception $e) {
            throw new Exception('Error generating ID', 0, $e);
        }
    }

    /**
     * @see get64
     * @deprecated 
     */
    public static function generateId64 (int $machineId = -1):int {
        return self::get64($machineId);
    }
}