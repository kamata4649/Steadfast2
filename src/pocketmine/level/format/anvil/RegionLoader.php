<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\level\format\anvil;

use pocketmine\level\format\LevelProvider;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\utils\Binary;
use pocketmine\utils\MainLogger;

class RegionLoader extends \pocketmine\level\format\mcregion\RegionLoader{

	public function __construct(LevelProvider $level, $regionX, $regionZ){
		$this->x = $regionX;
		$this->z = $regionZ;
		$this->levelProvider = $level;
		$this->filePath = $this->levelProvider->getPath() . "region/r.$regionX.$regionZ.mca";
		$exists = file_exists($this->filePath);
		touch($this->filePath);
		$this->filePointer = fopen($this->filePath, "r+b");
		stream_set_read_buffer($this->filePointer, 1024 * 16); //16KB
		stream_set_write_buffer($this->filePointer, 1024 * 16); //16KB
		if(!$exists){
			$this->createBlank();
		}else{
			$this->loadLocationTable();
		}

		$this->lastUsed = time();
	}

	public function readChunk($x, $z, $generate = true, $forward = false){
		$index = self::getChunkOffset($x, $z);
		if($index < 0 or $index >= 4096){
			return null;
		}

		$this->lastUsed = time();

		if(!$this->isChunkGenerated($index)){
			if($generate === true){
				//Allocate space
				$this->locationTable[$index][0] = ++$this->lastSector;
				$this->locationTable[$index][1] = 1;
				fseek($this->filePointer, $this->locationTable[$index][0] << 12);
				fwrite($this->filePointer, str_pad(pack("N", -1) . chr(self::COMPRESSION_ZLIB), 4096, "\x00", STR_PAD_RIGHT));
				$this->writeLocationIndex($index);
			}else{
				return null;
			}
		}

		fseek($this->filePointer, $this->locationTable[$index][0] << 12);
		$length = (PHP_INT_SIZE === 8 ? unpack("N", fread($this->filePointer, 4))[1] << 32 >> 32 : unpack("N", fread($this->filePointer, 4))[1]);
		$compression = ord(fgetc($this->filePointer));

		if($length <= 0 or $length >= self::MAX_SECTOR_LENGTH){ //Not yet generated / corrupted
			if($length >= self::MAX_SECTOR_LENGTH){
				$this->locationTable[$index][0] = ++$this->lastSector;
				$this->locationTable[$index][1] = 1;
				MainLogger::getLogger()->error("Corrupted chunk header detected");
			}
			$this->generateChunk($x, $z);
			fseek($this->filePointer, $this->locationTable[$index][0] << 12);
			$length = (PHP_INT_SIZE === 8 ? unpack("N", fread($this->filePointer, 4))[1] << 32 >> 32 : unpack("N", fread($this->filePointer, 4))[1]);
			$compression = ord(fgetc($this->filePointer));
		}

		if($length > ($this->locationTable[$index][1] << 12)){ //Invalid chunk, bigger than defined number of sectors
			MainLogger::getLogger()->error("Corrupted bigger chunk detected");
			$this->locationTable[$index][1] = $length >> 12;
			$this->writeLocationIndex($index);
		}elseif($compression !== self::COMPRESSION_ZLIB and $compression !== self::COMPRESSION_GZIP){
			MainLogger::getLogger()->error("Invalid compression type");

			return null;
		}

		$chunk = Chunk::fromBinary(fread($this->filePointer, $length - 1), $this->levelProvider);
		if($chunk instanceof Chunk){
			return $chunk;
		}elseif($forward === false){
			MainLogger::getLogger()->error("Corrupted chunk detected");
			$this->generateChunk($x, $z);

			return $this->readChunk($x, $z, $generate, true);
		}else{
			return null;
		}
	}

	public function generateChunk($x, $z){
		$nbt = new CompoundTag("Level", []);
		$nbt->xPos = new IntTag("xPos", ($this->getX() * 32) + $x);
		$nbt->zPos = new IntTag("zPos", ($this->getZ() * 32) + $z);
		$nbt->LastUpdate = new LongTag("LastUpdate", 0);
		$nbt->LightPopulated = new ByteTag("LightPopulated", 0);
		$nbt->TerrainPopulated = new ByteTag("TerrainPopulated", 0);
		$nbt->V = new ByteTag("V", self::VERSION);
		$nbt->InhabitedTime = new LongTag("InhabitedTime", 0);
		$nbt->Biomes = new ByteArrayTag("Biomes", str_repeat(chr(-1), 256));
		$nbt->BiomeColors = new IntArrayTag("BiomeColors", array_fill(0, 156, (PHP_INT_SIZE === 8 ? unpack("N", "\x00\x85\xb2\x4a")[1] << 32 >> 32 : unpack("N", "\x00\x85\xb2\x4a")[1])));
		$nbt->HeightMap = new IntArrayTag("HeightMap", array_fill(0, 256, 127));
		$nbt->Sections = new ListTag("Sections", []);
		$nbt->Sections->setTagType(NBT::TAG_Compound);
		$nbt->Entities = new ListTag("Entities", []);
		$nbt->Entities->setTagType(NBT::TAG_Compound);
		$nbt->TileEntities = new ListTag("TileEntities", []);
		$nbt->TileEntities->setTagType(NBT::TAG_Compound);
		$nbt->TileTicks = new ListTag("TileTicks", []);
		$nbt->TileTicks->setTagType(NBT::TAG_Compound);
		$writer = new NBT(NBT::BIG_ENDIAN);
		$nbt->setName("Level");
		$writer->setData(new CompoundTag("", ["Level" => $nbt]));
		$chunkData = $writer->writeCompressed(ZLIB_ENCODING_DEFLATE, RegionLoader::$COMPRESSION_LEVEL);
		$this->saveChunk($x, $z, $chunkData);
	}

}
