<?php

namespace Knik\Binn;

class BinnObject extends BinnAbstract
{
    protected $binnType = self::BINN_OBJECT;

    public function __construct($binnString = '')
    {
        $this->binnClass = self::class;

        if ($binnString != '') {
            $this->_binnLoad($binnString);
        }

        return $this;
    }

    /**
     * @param string
     */
    private function _binnLoad($binnString)
    {
        $pos = 1; // Position
        $sizeBytes = $this->unpack(self::BINN_UINT8, $binnString[$pos]);

        // Size
        if ($sizeBytes & 1 << 7) {
            $sizeBytes = $this->unpack(self::BINN_UINT32, substr($binnString, $pos, 4));
            $this->size = ($sizeBytes &~ (1 << 31)); // Cut bit
            $pos += 4;
        } else {
            $this->size = $sizeBytes;
            $pos += 1;
        }

        unset($sizeBytes);

        $countBytes = $this->unpack(self::BINN_UINT8, $binnString[$pos]);

        // Size
        if ($countBytes & 1 << 7) {
            $countBytes = $this->unpack(self::BINN_UINT32, substr($binnString, $pos, 4));
            $this->count = ($countBytes &~ (1 << 31)); // Cut bit
            $pos += 4;
        } else {
            $this->count = $countBytes;
            $pos += 1;
        }

        unset($countBytes);

        // Data
        $stopWhile = false;
        while ($pos < $this->size && !$stopWhile) {
            // Key size
            $varKeySize = $this->unpack(self::BINN_UINT8, substr($binnString, $pos, 1));
            $pos += 1;

            $varKey = $this->unpack(self::BINN_STRING, substr($binnString, $pos, $varKeySize));
            $pos += $varKeySize;

            $varType = $this->unpack(self::BINN_UINT8, $binnString[$pos]);
            $varStorageType = $this->storageType($varType);
            $pos += 1;

            if ($varStorageType === self::BINN_STORAGE_QWORD
                || $varStorageType === self::BINN_STORAGE_DWORD
                || $varStorageType === self::BINN_STORAGE_WORD
                || $varStorageType === self::BINN_STORAGE_BYTE
                || $varStorageType === self::BINN_STORAGE_NOBYTES
            ) {
                $varSize = $this->getTypeSize($varType);
                $val = $this->unpack($varType, substr($binnString, $pos, $varSize['data']));
                $this->_addVal($varKey, $varType, $val);
                $pos += $varSize['data'];

            } else if ($varStorageType === self::BINN_STRING ) {
                $stringSize = unpack("C", $binnString[$pos])[1];

                // Size
                if ($stringSize & 1 << 7) {
                    $stringSize = unpack("N", substr($binnString, $pos, 4))[1];
                    $stringSize = ($stringSize &~ (1 << 31)); // Cut bit
                    $pos += 4;
                } else {
                    $pos += 1;
                }

                $this->_addVal($varKey, self::BINN_STRING, unpack("a*", substr($binnString, $pos, $stringSize))[1]);
                $pos += $stringSize;
                $pos += 1; // Null byte
            } else if ($varStorageType === self::BINN_STORAGE_CONTAINER) {
                $list_size = unpack("C", $binnString[$pos])[1];

                // Size
                if ($list_size & 1 << 7) {
                    $list_size = unpack("N", substr($binnString, $pos, 4))[1];
                    $list_size = ($list_size &~ (1 << 31)); // Cut bit
                }

                $substring = substr($binnString, $pos-1, $list_size);
                $this->_addVal($varKey, $varType, new BinnList($substring));

                $pos += ($list_size-1);
            } else {
                $stopWhile = true;
            }
        }
    }

    /**
     * Get binary string
     *
     * @return string
     */
    public function getBinnVal()
    {
        $this->calculateSize();

        $this->binnString = '';
        $this->binnString .= pack("C", $this->binnType);

        $this->binnString .= ($this->size <= 127)
            ? pack("C", $this->size)
            : $this->getInt32Binsize($this->size);

        $count = count($this->binnArr);
        $this->binnString .= ($count <= 127)
            ? pack("C", $count)
            : $this->getInt32Binsize($count);

        foreach ($this->binnArr as &$arr) {
            $key = $arr[self::KEY_KEY];
            $type = $arr[self::KEY_TYPE];
            $storageType = $this->storageType($type);

            $this->binnString .= $this->pack(self::BINN_UINT8, mb_strlen($key));
            $this->binnString .= $this->pack(self::BINN_STRING, $key);

            if ($type === self::BINN_BOOL) {
                $this->binnString .= $arr[self::KEY_VAL]
                    ? $this->packType(self::BINN_TRUE)
                    : $this->packType(self::BINN_FALSE);

                continue;
            }

            if ($storageType === self::BINN_STORAGE_QWORD
                || $storageType === self::BINN_STORAGE_DWORD
                || $storageType === self::BINN_STORAGE_WORD
                || $storageType === self::BINN_STORAGE_BYTE
                || $storageType === self::BINN_STORAGE_NOBYTES
            ) {
                $this->binnString .= $this->packType($arr[self::KEY_TYPE]);
                $this->binnString .= $this->pack($arr[self::KEY_TYPE], $arr[self::KEY_VAL]);
            } else if ($storageType === self::BINN_STORAGE_STRING) {
                $this->binnString .= $this->packType(self::BINN_STRING);
                $this->binnString .= $this->packSize($arr[self::KEY_SIZE]);
                $this->binnString .= $this->pack(self::BINN_STRING, $arr[self::KEY_VAL]);
                $this->binnString .= $this->pack(self::BINN_NULL);
            } else if ($storageType === self::BINN_STORAGE_CONTAINER) {
                $this->binnString .= $arr[self::KEY_VAL]->getBinnVal();
            }
        }

        return $this->binnString;
    }

    /**
     * @param integer $key
     * @param int   $type
     * @param mixed $value
     */
    private function _addVal($key, $type, $value)
    {
        if (in_array($type,
            [self::BINN_INT64, self::BINN_INT32, self::BINN_INT16,
                self::BINN_UINT64,self::BINN_UINT32, self::BINN_UINT16])
        ) {
            $type = $this->compressInt($type, $value);
        }

        $size = $this->getTypeSize($type, $value);

        $this->dataSize += $size['data'];
        $this->metaSize += $size['meta'];

        // Key size. Size size + strlen
        $this->metaSize += 1 + strlen($key);

        $this->count++;

        $this->binnArr[] = [
            self::KEY_TYPE      => $type,
            self::KEY_VAL       => $value,
            self::KEY_SIZE      => $size['data'],
            self::KEY_KEY       => $key,
        ];
    }

    /**
     * @param array $array
     * @return string
     */
    public function serialize($array = [])
    {
        if (empty($array)) {
            return $this->getBinnVal();
        }

        $this->binnFree();

        if (! $this->isAssoc($array)) {
            throw new InvalidArrayException('Array should be associative');
        }

        foreach ($array as $key => $item) {
            $type = $this->detectType($item);
            $storageType = $this->storageType($type);

            if ($storageType === self::BINN_STORAGE_CONTAINER) {
                $binn = new BinnList();
                $binn->serialize($item);
                $item = $binn;
            }

            $this->_addVal($key, $type, $item);
        }

        return $this->getBinnVal();
    }

    /**
     * @param string $binnString
     * @return array
     */
    public function unserialize($binnString = '')
    {
        if (empty($binnString)) {
            return $this->getBinnArr();
        }

        $this->binnFree();

        $this->binnOpen($binnString);
        return $this->getBinnArr();
    }
}