<?php

namespace Espeo\SeamCarver;

use \Imagick;

class SeamCarver
{

  private $image;
  private $newImage;
  private $heatMap;
  private $maxHeat;
  private $seams;
  private $logger;

  public function __construct(\Imagick $image, $logger) {
    $this->image = $image;
    $this->newImage = clone $this->image;
    $this->logger = $logger;
  }

  private function getPixel($x, $y) {
    return $this->image->getImagePixelColor($x, $y)->getColor();
  }
  private function putPixel($x, $y, $color) {
    $colorRGB = "rgb({$color['r']},{$color['g']},{$color['b']})";
    $this->image->getImagePixelColor($x, $y)->setColor($colorRGB);
  }
  private function copyImage() {
    $this->newImage = clone $this->image;
  }
  
  private function b($x, $y) {
    if ($x < 0 || $y < 0 || $x >= $this->image->getImageWidth() || $y >= $this->image->getImageHeight()) {
	return 0;
    }
    $pixel = $this->getPixel($x, $y);
    return ($pixel['r'] + $pixel['g'] + $pixel['b']);
  }
  
  private function initHeatMap() {
    $heatMap = array();
    $max = 0;
    for ($x = 0; $x < $this->image->getImageWidth(); ++$x) {
        $heatMap[$x] = array();
        for ($y = 0; $y < $this->image->getImageHeight(); ++$y) {
            $xenergy = $this->b($x - 1, $y - 1) + 2 * $this->b($x - 1, $y) + $this->b($x - 1, $y + 1) - $this->b($x + 1, $y - 1) - 2 * $this->b($x + 1, $y) - $this->b($x + 1, $y + 1);
            $yenergy = $this->b($x - 1, $y - 1) + 2 * $this->b($x, $y - 1) + $this->b($x + 1, $y - 1) - $this->b($x - 1, $y + 1) - 2 * $this->b($x, $y + 1) - $this->b($x + 1, $y + 1);
            $heatMap[$x][$y] = sqrt($xenergy * $xenergy + $yenergy * $yenergy);
            $max = ($max > $heatMap[$x][$y] ? $max : $heatMap[$x][$y]);
        }
    }
    
    $this->heatMap = $heatMap;
    $this->maxHeat = $max;
    return $this;
  }
  private function initSeams() {
    $yseam = array();
    
    if(!isset($this->heatMap)) { 
      $this->initHeatMap();
    }
    
    $ylen = $this->image->getImageHeight() - 1;
    // initialize the last row of the seams
    for ($x = 0; $x < $this->image->getImageWidth(); ++$x) {
        $yseam[$x] = array();
        $yseam[$x][$ylen] = $x;
    }
    
    // sort the last row of the seams
    for ($i = 0; $i < count($yseam); ++$i) {
        for ($j = $i + 1; $j < count($yseam); ++$j) {
            if ($this->heatMap[$yseam[$i][$ylen]][$ylen] > $this->heatMap[$yseam[$j][$ylen]][$ylen]) {
                $tmp = $yseam[$j];
                $yseam[$j] = $yseam[$i];
                $yseam[$i] = $tmp;
            }
        }
    }
    
    // get the other rows of the seams
    for ($x = 0; $x < count($yseam); ++$x) {
        for ($y = $ylen - 1; $y >= 0; --$y) {
            $x1 = $yseam[$x][$y + 1];
            $x0 = $x1 - 1;
            // Move along till the adjacent pixel is not a part of another seam
            while ($x0 >= 0) {
                if (is_int($this->heatMap[$x0][$y])) {
                    break;
                }
                --$x0;
            }
            
            $x2 = $x1 + 1;
            while ($x2 < $this->image->getImageWidth()) {
                if (is_int($this->heatMap[$x2][$y])) {
                    break;
                }
                ++$x2;
            }
            $hx0 = isset($this->heatMap[$x0]) ? $this->heatMap[$x0][$y] : PHP_INT_MAX;
            $hx1 = isset($this->heatMap[$x1][$y]) ? $this->heatMap[$x1][$y] : PHP_INT_MAX;
            $hx2 = isset($this->heatMap[$x2]) ? $this->heatMap[$x2][$y] : PHP_INT_MAX;
            
            // Choose the least energy
            $yseam[$x][$y] = $hx0 < $hx1 ? ($hx0 < $hx2 ? $x0 : $x2) : ($hx1 < $hx2 ? $x1 : $x2);
            $this->heatMap[$yseam[$x][$y]][$y] = false;
        }
    }
    
    $this->seams = $yseam;
    return $this;
  }
  private function getHeatMap() {
    if(!isset($this->heatMap)) {
      $this->initHeatMap();
    }
    
    $this->copyImage();
    for ($x = 0; $x < $this->image->getImageWidth(); ++$x) {
        for ($y = 0; $y < $this->image->getImageHeight(); ++$y) {
            $color = (int)($this->heatMap[$x][$y] / $this->maxHeat * 255);
            $this->putPixel($x, $y, array(
                "r" => $color,
                "b" => $color,
                "g" => $color
            ));
        }
    }
    return $this->newImage;
  }
  private function getSeams() {
    if(!isset($this->seams)) {
      $this->initSeams();
    }
    
    $this->copyImage();
    $color = 255;
    $step = 1;//parseInt(color / this.image.width);
    for ($x = 0; $x < count($this->seams); ++$x) {
        for ($y = 0; $y < $this->image->getImageHeight; ++$y) {
            $this->putPixel($this->seams[$x][$y], $y, array('r'=>$color,'g'=>$color,'b'=>$color));
        }
        $color = ($color - $step < 0) ? 255 : $color - $step;
    }
    
    return $this->newImage;
  }
  public function resize($width, $height) {
    $image = $this->image;
    
    if(!isset($this->seams)) {
      $this->initSeams();
    }
    
    $widthDiff = $image->getImageWidth() - $width;
    
    for ($y = 0; $y < $image->getImageHeight(); ++$y) {
        $x1 = 0; // x counter of the new image
        for ($x = 0; $x < $image->getImageWidth(); ++$x) {
            $this->putPixel($x, $y, $this->getPixel($x, $y));
            $isSkippable = false;
            for ($i = 0; $i < $widthDiff; ++$i) {
                if ($this->seams[$i][$y] == $x) {
                    $isSkippable = true;
                    break;
                }
            }
            if ($isSkippable === false) {
                $this->putPixel($x1, $y, $this->getPixel($x, $y));
                ++$x1;
            }
        }
    }
    
    for ($x = $width; $x < $image->getImageWidth(); ++$x) {
        for ($y = 0; $y < $image->getImageHeight(); ++$y) {
            $this->putPixel($x, $y, array('r'=>0,'g'=>0,'b'=>0));
        }
    }
    
    return $this->newImage;
  }

}

$carver = new \Espeo\SeamCarver\SeamCarver(new Imagick('test.png'), null);
$carver->resize(100,100)->writeImage('test2.png'); ;