# exifDupeFinder

CLI tool to find photo duplicates in PHP & Python using ExifTool. Supports EXIF pattern matching, image hashing (visual duplicates), and smart caching.

## The HOPE Paradigm

Written ~~by kitten~~ with **HOPE**.

> "You're in control, but you don't have to use it for things to be good." :)

---

### What is HOPE?

This project is built for **Maximum User Comfort**. My core philosophy is to provide a system that works perfectly by default, allowing you to step in only when you *want* to, not because you *have* to.

* **H** – **Human-centric**: Intuitive design tailored for tired eyes and busy minds. 
* **O** – **Overridable**: Intelligent defaults that stay out of your way, but allow for deep, granular configuration whenever you need it.
* **P** – **Predictable**: Pure simplicity with zero surprises. You look at the code or the output, and you just know how it works.
* **E** – **Effortless**: Powerful out-of-the-box experience. The system handles the heavy lifting so you don't have to.

## Changelog
### 0.0.2
* Added config setting `prettyPrint` - if disabled you'll get CSV output for easier processing!
* config file and readme now have more human-friendly language

---
## General information, tips and tricks

### Finding the right tags
If you want to customize `fetchTags` or `compareTags`, you need to know exactly how ExifTool sees your files. Depending on your needs, use one of these commands:

* **The Quick Way:**
  ```bash
  exiftool -args [filename]
  ```
  Simple and readable. Great for standard tags like `Model` or `DateTimeOriginal`.

* **The Secure/Precise Way (Recommended):**
  ```bash
  exiftool -args -G0 [filename]
  ```
This displays the group name before each tag (e.g., `-EXIF:DateTimeOriginal`). Use the **full name** (including the group and colon) in your configuration to ensure maximum precision and avoid metadata conflicts.

### Searching by dates trick
The default configuration uses a combination of `DateTimeOriginal` and `SubSecTime`. 

* **DateTimeOriginal**: This is the "standard" timestamp when the photo was actually taken (stored in EXIF).
* **SubSecTime**: This is the "secret sauce" for burst mode. Modern cameras and phones often shoot several photos within a single second. While they all share the same `DateTimeOriginal`, the `SubSecTime` stores the fractions of a second.

**The HOPE way:** If your files don't have sub-second data, don't worry. The script is designed to be resilient—if a tag like `SubSecTime` is missing in a file, it simply treats it as an empty string and continues comparing based on the remaining tags (like `DateTimeOriginal`). This ensures accuracy for pro cameras and most smartphones without breaking compatibility for older files.

### The "Deep Scan" mode (image data hashing)
This is the most powerful feature of the tool, but it requires a bit of understanding to use effectively:

* **What it does:** This hash is calculated **only from the pixel data**. :warning: It is **not** a perceptual (visual) hash. For similarity-based comparison (finding photos that look the same but have different compression or resolution), use tools like [Czkawka](https://github.com/qarmin/czkawka), which perfectly complements **exifDupeFinder**.
* **Why it’s awesome:** It will find duplicates even if metadata is inconsistent across files. For example, it will match two identical photos even if one has a title in the EXIF section and the other had its metadata stripped but later gained a Title in the XMP section.
* **The Trade-off:** It is significantly slower. For metadata, ExifTool only reads the first few kilobytes of a file. For hashing, it must process and calculate the entire image payload.
    * **Usage Tip:** Use this mode when you suspect your library has inconsistent metadata or you have multiple copies of the same image saved by different software.

### :warning: A Note on cache
The script creates a `.csv` cache file (e.g., `exifdata-xxxx.csv`) inside the **target photo directory**. It works for `fetchTags` and the `photoDir` pair. This allows you to change `compareTags` and re-run the script to see different results instantly without without the time-consuming process of re-scanning thousands of files. 

**Privacy & Security:**
* The cache is **not automatically deleted** to save you time on subsequent runs. 
* This file contains metadata, filenames, and paths of your photos. 
* If you are on a shared system, remember to delete it manually after use. 

---

## Getting Started

### Prerequisites
The script is a wrapper for the legendary **ExifTool**. You must have it installed and available in your system PATH.
* **Official Binary:** [exiftool.org](https://exiftool.org/)
* **Alternative Windows Build (Fast):** [Oliver Betz's ExifTool for Windows](https://oliverbetz.de/pages/Artikel/ExifTool-for-Windows)

### Compatibility
* **PHP:** 7.4, 8.x+
* **Python:** 3.10 through 3.14+

### Running the Tool
You have two ways to point the script to your photos:

1. **Via Command Line (Recommended):**  
   Pass the path as the first argument:
```bash
php exifDupeFinder.php /path/to/photos
# OR
python exifDupeFinder.py /path/to/photos
```

2. **Via Script Configuration:**  
   If no argument is passed via CLI, the tool uses the `photoDir` value from the [Configuration](#configuration--customization) section. If that is also empty, it defaults to the current directory `"."`.

---

## Configuration & Customization

The script uses a `config.ini` file for settings. If you want to reset everything, just delete `config.ini` and the script will create a fresh one with default settings.

ℹ️ For best results in both PHP and Python, use `0` for **false** and `1` for **true**. While other supported literals exist, namely `true`/`false`, `on`/`off`, and `yes`/`no`, using `0`/`1` is the most reliable.

### Core Settings

* **`photoDir`** (string)  
  The folder where your photos are kept. 
  * **Default:** `""` (the script looks in its own folder). 
  * You can also drag and drop a folder onto the script to use it instead.

* **`imageHashMode`** (bool)  
  **The "Deep Scan" mode.** When turned on (`1`), the script looks at the actual pixels of the photo instead of its internal descriptions. It is very accurate but slower.
  * **Default:** `0` (off).
  * **Note:** Turning this on ignores your tag settings and focuses strictly on the image content.

* **`extensions`** (string)  
  Which types of files should be checked (comma-separated). 
  * **Default:** `"jpg,jpeg,cr2,nef,mrw,tif,tiff"`
  * If you leave this empty `""`, the script will try to check every file it finds.

### Exif & Tag Settings

* **`fetchTags`** (string)  
  Specific information to read from files.
  * **Default:** `"DateTimeOriginal,SubSecTime"`
  * 💡 **Tip:** Don't be afraid to add more tags here (like `Title` or `Model`). It doesn't really slow down the script, and it gives you more options to compare photos later.

* **`compareTags`** (string)  
  Which information should be used to decide if two photos are "the same".
  * **Default:** If left empty `""`, the script automatically uses everything listed in `fetchTags`.
  * *Example:* If you set this to `DateTimeOriginal`, files will be marked as duplicates if they were taken at the exact same second, even if they have different filenames.

* **`exifToolParameters`** (string)  
  Advanced settings for the built-in reading engine.
  * **Default:** `"-m"` (tells the script to ignore small, unimportant file errors).

### Tooling & UI

* **`displayOutput`** (int)  
  How much technical information you want to see while the script is working.
  * `0`: Silent – shows nothing.
  * `1`: Errors only – shows if something goes wrong.
  * `2`: Full – shows everything (default).

* **`prettyPrint`** (bool)  
  How the final results are shown on your screen.
  * `1`: **Beautiful** – a clear, colorful list for people (default).
  * `0`: **Data (CSV)** – a simple list for Excel or other programs (`group_id,"path",size_difference`). This also hides technical messages.

* **`enableColorOutput`** (bool)  
  Adds colors to the text to make it easier to read on the screen.
  * **Default:** `1` (on).
  * Set to `0` if you are saving the results to a text file and don't want to see "messy characters" (color codes).

---

## Usage Examples

In these scenarios, we assume a test environment with **three files**: two are binary identical, and the third is visually the same but has a different metadata `Title`.

### Scenario A: Strict Metadata Comparison (Initial Run)
In this case, we fetch three tags and use them all for comparison. Since one file has a different title, it is not considered a duplicate yet.

* **Configuration:** * `fetchTags = "DateTimeOriginal,SubSecTime,Title"`
  * `compareTags = ""`
* **Result:** The script runs ExifTool, creates a cache file in the photo directory, and finds **1 duplicate** (the binary identical one). The third file is excluded because the `Title` tag doesn't match.

<img width="1204" height="477" alt="c1" src="https://github.com/user-attachments/assets/dd18ebf7-39dc-4460-a597-a33dbc008165" />

### Scenario B: Refined Comparison (Using Cache)
Here, we decide that the `Title` isn't important. We change the configuration to ignore it. 

* **Configuration:** * `fetchTags = "DateTimeOriginal,SubSecTime,Title"`
  * `compareTags = "DateTimeOriginal,SubSecTime"`
* **Result:** The script detects the existing cache and **skips ExifTool execution**. It instantly finds **2 duplicates** because we narrowed the comparison criteria. This demonstrates how you can refine results without re-scanning files.

<img width="1204" height="237" alt="c2" src="https://github.com/user-attachments/assets/24ad10fb-c3d5-442a-904c-993fa0b9c219" />

### Scenario C: The Visual Deep Dive (imageHashMode)
When metadata is unreliable or missing, we switch to pixel-based hashing.

* **Configuration:** * `imageHashMode = true`
* **Result:** The script **overrides** all tag settings and generates a visual hash of the image content using `ImageDataHash`. It finds that all **3 files** are duplicates because the actual image data is identical, regardless of titles or timestamps.

<img width="1204" height="470" alt="c3" src="https://github.com/user-attachments/assets/e0357d06-1402-4899-b909-7ab8e961da7c" />

---

# ☕ Support the effort
If this tool saved your time (and your sanity) while cleaning up your photo library, consider supporting the development. 

The **HOPE** philosophy means I build tools that are helpful and free. Your support helps me keep it that way!

[!["Buy Me A Coffee"](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/krzysiunet)

**Every bit helps. Thanks for being part of the community!**
