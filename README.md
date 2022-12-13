# Laravel Dominant Color

This laravel package is made from a fork of [naomi/php-dominantcolor](https://github.com/naomai/php-dominantcolor) repository

This laravel package extracts dominant colors from images - those, which are most likely to attract the viewer's eyes.

![Album arts example](example/albums.jpg)

The library uses [K-means clustering](https://en.wikipedia.org/wiki/K-means_clustering) method to group image pixels into different *color bins*. Those colors are then evaluated by their saturation and differences between others. Two of them are picked as *most prominent* (primary and secondary).

## Requirements

- PHP 8 or higher
- Laravel 8 or higher

## Installation

Install the package in your application by running

```bash
composer require capsulescodes/laravel-dominant-color
```


## Usage

We want to find most prominent colors with three additional colors from image ```heart.png```.

You can acces the DominantColor via Facade like the following :

```PHP
$imageSrc = "heart.png";
$totalNumberOfColors = 5; // primary + secondary + 3 additional

DominantColor::fromFile($imageSrc, $totalNumberOfColors);

```

Number of colors is the number of *color bins*, it also should include primary and secondary colors.

The result is an array of following structure:
```PHP
$colorInfo = [
	'primary' => 0xDEFDEF,
	'secondary' => 0xABCABC,
	'palette' => [
		0 => ['color' => 0xABCABC, 'score' => 0.8],
		/// etc
	];
```

All colors are formatted as standard RGB integer in form: ```0xRRGGBB```.

```palette``` contains additional colors. The ```score``` field is a score from secondary color choosing algorithm. The chosen secondary color has value of ```1.0```.

See **examples** directory for a visual demonstration.

This project relies on the elegant [PHP K-Means](https://github.com/bdelespierre/php-kmeans/) library by Benjamin Delespierre.
