# Magic Photo Gallery

This is an all-in-one image gallery which you simply put inside your images directory and open with a browser.
It requires and HTTPD server with PHP support.

Just simply use my [docker image](https://github.com/napalmz/httpd-base), as shown [here](#Deployment).

## Screenshots

![Magic Photo Gallery with monochrome icons and photo sorting](./screenshot.png)

Interface icons use monochrome, Material Design-inspired inline SVGs, with no external fonts or icon downloads. They inherit the surrounding text color; authentication indicators remain green (unlocked) or red (locked).

## Languages

The UI is translated into 32 European languages (en, it, fr, de, es, pt, nl, pl, ro, cs, sk, sl, hr, bg, el, hu, fi, sv, da, no, is, et, lv, lt, ga, mt, ca, uk, ru, sr, sq, mk).

- By default the language follows the browser (`Accept-Language`), falling back to English.
- The menu has a language selector; the choice is saved in `.Magic-Photo-Gallery/.config.json` (`"language": "auto"` or a code such as `"de"`).
- The saved language is global for all visitors. With `auth_mode` 1/2 only logged-in users can change it.

## Photo ordering

Photos are ordered by capture date (EXIF, falling back to the file modification date), oldest first. Use the selector above the photo grid to sort by date, file name, or file size, in either direction. The selected order is saved in the browser for each folder.

Folder lists have their own selector: sort by name, the number of images directly inside each folder, or their combined image-file size. Name ascending is the default, and the selection is saved per folder.

## Deployment

First, move to your image directory and download the app:

```
wget -O index.php https://raw.githubusercontent.com/napalmz/Magic-Photo-Gallery/main/index.php
```

Then, spinup a docker container (using compose):
```compose
services:
  httpd-server-main:
    image: napalmzrpi/httpd-base:latest
    container_name: httpd-server-main
    environment:
      - TZ=Europe/Rome
    volumes:
      - "/my/app/directory/config:/var/www/site"        # Store PHP app and configurations
      - "/my/app/directory/log:/var/log/apache2"        # Store APACHE/PHP logs
      - "/my/photo/directory1:/var/www/site/Photo 1:ro" # Safely store pictures, it's READ ONLY
      - "/my/photo/directory2:/var/www/site/Photo 2:ro" # Safely store pictures, it's READ ONLY
      - "/my/photo/directory3:/var/www/site/Photo 3:ro" # Safely store pictures, it's READ ONLY
    ports:
      - 80:80
    restart: always
```
