<?php
    
    /**
        * ExifDupeFinder
        * Finds duplicate photos based on Exif metadata or image content.
        * http://github.com/Krzysiu/exifDupeFinder
        * <3 https://buymeacoffee.com/krzysiunet <3
        *
        * @author Krzysiu
        * @version 0.0.2
        * @link http://github.com/Krzysiu/exifDupeFinder
    */
    
    $scriptDir = __DIR__ . DIRECTORY_SEPARATOR;
    $defaultPath = $scriptDir . 'config.default';
    $iniPath = $scriptDir . 'config.ini';
    
    if (!file_exists($iniPath) && file_exists($defaultPath)) {
        copy($defaultPath, $iniPath);
        echo "Created local config.ini from default template." . PHP_EOL;
    }
    
    $iniData = parse_ini_file($iniPath, true);
    $config = [];
    foreach ($iniData as $values) {
        foreach ($values as $key => $val) {
            $config[$key] = $val;
        }
    }
    
    $fetchTags = $config['fetchTags'] ?? "";
    $compareTagsStr = !empty($config['compareTags']) ? $config['compareTags'] : $fetchTags;
    $compareTagsArray = array_map('trim', explode(',', $compareTagsStr));
    
    // imageHashMode override - MUST be here before cacheSum
    if (filter_var($config['imageHashMode'], FILTER_VALIDATE_BOOLEAN)) {
        $fetchTags = 'ImageDataHash';
        $compareTagsArray = ['ImageDataHash'];
    }
    
    $useColor = filter_var($config['enableColorOutput'], FILTER_VALIDATE_BOOLEAN);
    $prettyPrint = filter_var($config['prettyPrint'], FILTER_VALIDATE_BOOLEAN);
    if (!$prettyPrint) $displayLevel = 0;
    
    $red    = $useColor ? "\033[1;31m" : "";
    $cyan   = $useColor ? "\033[0;36m" : "";
    $white  = $useColor ? "\033[1;37m" : "";
    $green  = $useColor ? "\033[0;32m" : "";
    $reset  = $useColor ? "\033[0m"    : "";
    
    $targetDir = $argv[1] ?? $config['photoDir'];
    if (empty($targetDir)) $targetDir = ".";
    $cleanPhotoDir = realpath($targetDir);
    
    if (!$cleanPhotoDir || !is_dir($cleanPhotoDir)) {
        die("{$red}Error: Directory does not exist: {$white}$targetDir{$reset}" . PHP_EOL);
    }
    
    // Cache is stored in the photos directory as requested
    $cacheSum = hash('crc32b', $fetchTags . $cleanPhotoDir);
    $cacheFile = $cleanPhotoDir . DIRECTORY_SEPARATOR . "exifdata-{$cacheSum}.csv";
    
    function parseParamString($str, $prefix) {
        if (empty(trim($str))) return "";
        $parts = explode(',', $str);
        $formatted = array_map(function($item) use ($prefix) {
            return $prefix . trim($item);
        }, $parts);
        return implode(' ', $formatted);
    }
    
    if (!file_exists($cacheFile)) {
        if ($prettyPrint) {
            echo "{$white}Comparing files in {$red}{$cleanPhotoDir}{$reset}" . PHP_EOL;
            echo "{$white}Fetching {$red}{$fetchTags}{$white}...{$reset}" . PHP_EOL;
        }    
        $cmdPart = parseParamString($fetchTags, "-");
        $extPart = parseParamString($config['extensions'], "-ext ");
        
        $cmd = sprintf(
        'exiftool %s %s -csv -n %s -r %s',
        $config['exifToolParameters'],
        $extPart,
        $cmdPart,
        escapeshellarg($cleanPhotoDir)
        );
        
        if ($prettyPrint) echo PHP_EOL . "{$white}Running: {$red}$cmd{$reset}" . PHP_EOL;
        
        $displayLevel = (int)($config['displayOutput'] ?? 0);
        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $redirect = " > " . escapeshellarg($cacheFile);
        
        if ($displayLevel === 0) {
            $redirect .= $isWin ? " 2>nul" : " 2>/dev/null";
        }
        
        if ($displayLevel > 0) {
            echo PHP_EOL . "{$green}--EXIFTOOL OUTPUT--{$reset}" . PHP_EOL;
            if (ob_get_level()) ob_end_flush();
            flush();
        }
        
        passthru($cmd . $redirect);
        
        
        if ($displayLevel > 0) {
            echo PHP_EOL . "{$green}--END OF THE EXIFTOOL OUTPUT--{$reset}" . PHP_EOL . PHP_EOL;
        }
        } elseif ($prettyPrint) {
        echo "{$white}Comparing files in {$red}{$cleanPhotoDir}{$reset}" . PHP_EOL;
        echo "{$white}Loading data from cache: {$red}{$cacheFile}{$reset}" . PHP_EOL;
    }
    
    if ($prettyPrint) {
        if (filter_var($config['imageHashMode'], FILTER_VALIDATE_BOOLEAN)) {
            echo "{$green}Using {$red}image hash comparison{$green} mode{$reset}" . PHP_EOL . PHP_EOL;
            } else {
            echo "{$green}Result using \"{$red}" . implode(',', $compareTagsArray) . "{$green}\" tags:{$reset}" . PHP_EOL . PHP_EOL;
        }
    }
    
    $groups = [];
    $totalDuplicates = 0;
    $totalWastedSpace = 0;
    
    if (file_exists($cacheFile) && ($handle = fopen($cacheFile, "r")) !== false) {
        $header = fgetcsv($handle, 0, ",", "\"", "\\");
        $headerUpper = array_map('strtoupper', $header);
        
        while (($row = fgetcsv($handle, 0, ",", "\"", "\\")) !== false) {
            if (count($header) !== count($row)) continue;
            $rowData = array_combine($header, array_map(function($v) { return $v ?? ""; }, $row));
            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rowData['SourceFile'] ?? $rowData['FilePath']);
            
            $compareString = "";
            foreach ($compareTagsArray as $tagName) {
                $index = array_search(strtoupper($tagName), $headerUpper);
                if ($index !== false && isset($row[$index]) && $row[$index] !== "") {
                    $compareString .= $row[$index];
                }
            }
            
            if ($compareString !== "") {
                $groups[$compareString][] = $filePath;
            }
        }
        fclose($handle);
    }
    
    $i = 1;
    $found = false;
    
    foreach ($groups as $signature => $files) {
        if (count($files) > 1) {
            $found = true;
            $totalDuplicates += (count($files) - 1);
            
            if ($prettyPrint) echo "{$white}Group #{$red}$i{$white}:{$reset}" . PHP_EOL;
            
            $originalSize = 0;
            foreach ($files as $index => $file) {
                $fullPath = (file_exists($file)) ? $file : $cleanPhotoDir . DIRECTORY_SEPARATOR . basename($file);
                $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
                
                $delta = 0;
                if ($index === 0) {
                    $originalSize = $fileSize;
                    } else {
                    $totalWastedSpace += $fileSize;
                    $delta = $fileSize - $originalSize;
                }
                
                if ($prettyPrint) {
                    echo "{$white}* {$cyan}$file {$white}({$red}" . humanSize($fileSize) . "{$white})";
                    
                    if ($index === 0) {
                        echo " {$white}assumed original";
                        } else {
                        if ($fileSize === $originalSize) {
                            echo " {$white}same size";
                            } elseif ($fileSize < $originalSize) {
                            echo " {$white}smaller: {$green}-" . humanSize(abs($delta));
                            } else {
                            echo " {$white}larger: {$red}+" . humanSize($delta);
                        }
                    }
                    echo "{$reset}" . PHP_EOL;
                    } else {
                    echo "{$i},\"{$file}\",{$delta}" . PHP_EOL;
                }
            }
            
            if ($prettyPrint) echo PHP_EOL;
            $i++;
        }
    }
    if ($prettyPrint) {
        if ($found) {
            $groupCount = $i - 1;
            echo "{$red}{$totalDuplicates}{$white} duplicates found in {$red}{$groupCount}{$white} groups.{$reset}" . PHP_EOL;
            echo "{$white}Space wasted by duplicates: {$red}" . humanSize($totalWastedSpace) . "{$reset}" . PHP_EOL;
            } else {
            echo "{$white}No duplicates found based on the current criteria.{$reset}" . PHP_EOL;
        }
    }
    function humanSize($size, $format = [2]) {
        if ($size <= 0) return "0 B";
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = floor(log($size, 1024));
        return number_format($size / pow(1024, $i), ...$format) . ' ' . $units[$i];
    }            