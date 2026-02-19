import os
import sys
import configparser
import shutil
import subprocess
import csv
import math
import zlib

# Restore original Windows ANSI color support
if os.name == 'nt':
    import ctypes
    try:
        kernel32 = ctypes.windll.kernel32
        kernel32.SetConsoleMode(kernel32.GetStdHandle(-11), 7)
    except: pass

scriptDir = os.path.dirname(os.path.abspath(__file__)) + os.sep
iniPath = scriptDir + 'config.ini'

# Equivalent of parse_ini_file($iniPath, true)
iniData = configparser.ConfigParser(inline_comment_prefixes=';')
iniData.optionxform = str 
iniData.read(iniPath)

config = {}
for section in iniData.sections():
    for key, val in iniData.items(section):
        config[key] = val.strip('"').strip("'")

# --- PHP LOGIC REPLICA ---
fetchTags = config.get('fetchTags', "")
compareTagsStr = config.get('compareTags', "")

if not compareTagsStr or compareTagsStr.strip() == "":
    compareTagsStr = fetchTags

compareTagsArray = [item.strip() for item in compareTagsStr.split(',')] if compareTagsStr else []

if config.get('imageHashMode', '').lower() in ('true', '1', 'yes', 'on'):
    fetchTags = 'ImageDataHash'
    compareTagsArray = ['ImageDataHash']

# Colors
useColor = config.get('enableColorOutput', '').lower() in ('true', '1', 'yes', 'on')
red, cyan, white, green, reset = ("", "", "", "", "")
if useColor:
    red, cyan, white, green, reset = ("\033[1;31m", "\033[0;36m", "\033[1;37m", "\033[0;32m", "\033[0m")

targetDir = sys.argv[1] if len(sys.argv) > 1 else config.get('photoDir', '')
if not targetDir: targetDir = "."
cleanPhotoDir = os.path.realpath(targetDir)

# CacheSum Calculation (CRC32b)
cacheSum = format(zlib.crc32((fetchTags + cleanPhotoDir).encode()) & 0xFFFFFFFF, '08x')
cacheFile = os.path.join(cleanPhotoDir, f"exifdata-{cacheSum}.csv")

def humanSize(size, decimals=2):
    if size <= 0: return "0 B"
    units = ['B', 'kB', 'MB', 'GB', 'TB']
    i = int(math.floor(math.log(size, 1024)))
    res = size / pow(1024, i)
    return f"{res:.{decimals}f} {units[i]}"

# --- EXIFTOOL EXECUTION ---
if not os.path.exists(cacheFile):
    print(f"{white}Comparing files in {red}{cleanPhotoDir}{reset}")
    print(f"{white}Fetching {red}{fetchTags}{white}...{reset}")
    
    fetchTagsParams = " ".join(["-" + t.strip() for t in fetchTags.split(',') if t.strip()])
    extParams = " ".join(["-ext " + e.strip() for e in config.get('extensions', '').split(',') if e.strip()])
    
    cmd = f'exiftool {config.get("exifToolParameters", "")} {extParams} -csv -n {fetchTagsParams} "{cleanPhotoDir}"'
    
    print(f"\n{white}Running: {red}{cmd}{reset}")
    
    displayLevel = int(config.get('displayOutput', 0))
    with open(cacheFile, 'w', encoding='utf-8') as f:
        if displayLevel > 0:
            print(f"\n{green}--EXIFTOOL OUTPUT--{reset}")
            sys.stdout.flush() # Ensure header prints before exiftool starts
            
        stderr_val = subprocess.DEVNULL if displayLevel == 0 else None
        
        # PHP passthru() effect:
        subprocess.run(cmd, shell=True, stdout=f, stderr=stderr_val)
        
        if displayLevel > 0:
            print(f"\n{green}--END OF THE EXIFTOOL OUTPUT--{reset}\n")
else:
    print(f"{white}Comparing files in {red}{cleanPhotoDir}{reset}")
    print(f"{white}Loading data from cache: {red}{cacheFile}{reset}")

print(f"{green}Result using \"{red}{','.join(compareTagsArray)}{green}\" tags:{reset}\n")

# --- DUPLICATE FINDING LOGIC ---
groups = {}
if os.path.exists(cacheFile):
    with open(cacheFile, mode='r', newline='', encoding='utf-8') as csvfile:
        reader = csv.reader(csvfile)
        try:
            header = next(reader)
            headerMap = {h: i for i, h in enumerate(header)}
            
            for row in reader:
                if not row: continue
                
                sourceFile = row[headerMap.get('SourceFile', 0)]
                filePath = sourceFile.replace('/', os.sep).replace('\\', os.sep)
                
                signatureParts = []
                for tag in compareTagsArray:
                    idx = headerMap.get(tag)
                    if idx is not None and idx < len(row):
                        val = row[idx].strip()
                        if val:
                            signatureParts.append(val)
                
                signature = "".join(signatureParts)
                
                if signature:
                    if signature not in groups:
                        groups[signature] = []
                    groups[signature].append(filePath)
        except StopIteration:
            pass

# --- OUTPUT GENERATION ---
i = 1
totalDuplicates = 0
totalWastedSpace = 0
found = False

for sig, files in groups.items():
    if len(files) > 1:
        found = True
        totalDuplicates += (len(files) - 1)
        print(f"{white}Group #{red}{i}{white}:{reset}")
        originalSize = 0
        
        for idx, f in enumerate(files):
            # Resolve full path to get file size
            if os.path.isabs(f):
                fullPath = f
            else:
                fullPath = os.path.join(cleanPhotoDir, os.path.basename(f))
                if not os.path.exists(fullPath):
                    # Check relative to parent of photo dir if exiftool was run from above
                    fullPath = os.path.join(os.path.dirname(cleanPhotoDir), f)
            
            fSize = os.path.getsize(fullPath) if os.path.exists(fullPath) else 0
            
            print(f"{white}* {cyan}{f} {white}({red}{humanSize(fSize)}{white})", end="")
            
            if idx == 0:
                originalSize = fSize
                print(f" {white}assumed original{reset}")
            else:
                totalWastedSpace += fSize
                diff = fSize - originalSize
                if diff == 0:
                    suffix = "same size"
                elif diff < 0:
                    suffix = f"smaller: {green}-{humanSize(abs(diff))}"
                else:
                    suffix = f"larger: {red}+{humanSize(diff)}"
                print(f" {white}{suffix}{reset}")
        print()
        i += 1

if found:
    print(f"{red}{totalDuplicates}{white} duplicates found in {red}{i-1}{white} groups.{reset}")
    print(f"{white}Space wasted by duplicates: {red}{humanSize(totalWastedSpace)}{reset}")
else:
    print(f"{white}No duplicates found based on the current criteria.{reset}")