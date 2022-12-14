<?php

namespace CapsulesCodes\DominantColor;

use KMeans\Space;
use KMeans\Cluster;
use CapsulesCodes\DominantColor\Utils\ColorConversion;

class DominantColor
{
    /* define how many pixels horizontally and vertically are taken into account
        100x100 is more than enough to get colors from album covers
    */
    protected $calcWidth = 100;
    protected $calcHeight = 100;

    public function __construct()
    {
        // throw new \Exception(get_class() . " is an utility class and should be used statically");
    }

    public function fromGD($gdImage, int $colorCount = 2): ColorPalette
    {
        $colorCount = max($colorCount, 2); // at least 2 colors - primary and secondary

        $space = $this->imageToKSpace($gdImage);

        $clusters = $space->solve(
            nbClusters: $colorCount,
            initMethod: Cluster::INIT_KMEANS_PLUS_PLUS
        );

        /* score calculation for primary and secondary dominant color */
        $scores = $this->createScoreArray($clusters);
        $primary = $this->findPrimaryColor($scores);
        $secondary = $this->findSecondaryColor($scores);

        /* //display colors with their scores
        foreach($scores['clusters'] as &$c){
            printf("<span style='background: #%06x'>Cluster %d points p_score=%.02f s_score=%.02f [S=%.02f,V=%.02f]</span><br/>\n", $c['color'], $c['count'], $c['p_score'], $c['s_score'], $c['s'], $c['v']);
        }*/

        $palette = [];
        foreach ($scores['clusters'] as &$c) {
            if ($c['color'] != $primary->color() && $c['color'] != $secondary->color()) {
                $palette[] = new Color($c);
                // [
                //     'color' => ColorConversion::hsv2hex($c['h'], $c['s'], $c['v']),
                //     'score' => $c['s_score'] / $scores['secondary']['maxScore']
                // ];
            }
        }
        usort($palette, function ($a, $b) {
            return $b->score() <=> $a->score();
        });

        $colorPalette = new ColorPalette(
            primary: $primary,
            secondary: $secondary,
            palette: $palette
        );

        return $colorPalette;
        // return [
        //     'primary' => ColorConversion::hsv2hex($primary['h'], $primary['s'], $primary['v']),
        //     'secondary' => ColorConversion::hsv2hex($secondary['h'], $secondary['s'], $secondary['v']),
        //     'palette' => $palette
        // ];
    }

    public function fromFile(string $fileName, int $colorCount = 2): ColorPalette
    {
        $gdImg = imagecreatefromstring(file_get_contents($fileName));

        if (!$gdImg) {
            throw new \Exception("Could not load image from file $fileName");
        }

        $colorInfo = $this->fromGD($gdImg, $colorCount);
        imagedestroy($gdImg);

        return $colorInfo;
    }

    private function imageToKSpace($gdImage): Space
    {
        $wImage = imagesx($gdImage);
        $hImage = imagesy($gdImage);

        $xSkip = max($wImage / $this->calcWidth, 1);
        $ySkip = max($hImage / $this->calcHeight, 1);

        $space = new Space(3);

        // walk through the pixels
        for ($y=0; $y<$hImage; $y+=$ySkip) {
            for ($x=0; $x<$wImage; $x+=$xSkip) {
                $xRGB = imagecolorat($gdImage, floor($x), floor($y));
                $aRGB = ColorConversion::hex2rgb($xRGB);
                $aHSV = ColorConversion::rgb2hsv($aRGB[0], $aRGB[1], $aRGB[2]);

                // convert HSV to coordinates in cone
                $pr = $aHSV[1] * $aHSV[2]; // radius

                $px = sin($aHSV[0] * 2 * M_PI) * $pr;
                $py = cos($aHSV[0] * 2 * M_PI) * $pr;
                $pz = $aHSV[2] * config('dominant-color.kspace.valueDistanceMultiplier');

                $space->addPoint([$px, $py, $pz], [$aHSV, $xRGB]);
            }
        }

        return $space;
    }

    private function createScoreArray(array $clusters): array
    {
        $clusterScore = [];
        $maxCount = 0;
        $maxS = 0;
        $maxV = 0;

        foreach ($clusters as $i => $cluster) {
            if (!count($cluster)) {
                continue;
            }
            $closest = $cluster->getClosest($cluster);

            $colors = $closest->toArray()['data'];
            $aHSV = $colors[0];
            $xRGB = $colors[1];
            $clusterCount = count($cluster);

            $clusterScore[] = [
                "clusterObj"=>$cluster,
                "color"=>$xRGB,
                "h"=>$aHSV[0],
                "s"=>$aHSV[1],
                "v"=>$aHSV[2],
                "count"=>$clusterCount
            ];

            $maxCount = max($maxCount, $clusterCount);
            $maxS = max($maxS, $aHSV[1]);
            $maxV = max($maxV, $aHSV[2]);
        }

        if (!$maxS) {
            $maxS = 1;
        }
        if (!$maxV) {
            $maxV = 1;
        }
        return ['clusters'=>$clusterScore, 'maxCount'=>$maxCount, 'maxS'=>$maxS, 'maxV'=>$maxV];
    }

    private function findPrimaryColor(array &$scoreArray)
    {
        foreach ($scoreArray['clusters'] as &$c) {
            [$sf, $vf, $cf] = $this->normalizeColor($c, $scoreArray);
            $scorePrimary = $sf * config('dominant-color.primary.saturationMultiplier');
            $scorePrimary += $vf * config('dominant-color.primary.valueMultiplier');
            $scorePrimary += $cf * config('dominant-color.primary.countMultiplier');
            $c['p_score'] = $scorePrimary;

            if ($c['s'] < $scoreArray['maxS'] * config('dominant-color.saturationLowThreshold')) {
                $c['p_score'] *= config('dominant-color.saturationLowMultiplier');
            }
            if ($c['v'] < $scoreArray['maxV'] * config('dominant-color.valueLowThreshold')) {
                $c['p_score'] *= config('dominant-color.valueLowMultiplier');
            }
        }

        $maxPScore = 0;
        $primaryIdx = 0;

        array_walk($scoreArray['clusters'], function ($c, $idx) use (&$maxPScore, &$primaryIdx) {
            if ($c['p_score'] > $maxPScore) {
                $maxPScore = $c['p_score'];
                $primaryIdx = $idx;
            }
        });
        $scoreArray['primary'] = ['maxScore'=>$maxPScore, 'idx'=>$primaryIdx];

        return new Color($scoreArray['clusters'][$primaryIdx]);
    }

    private function findSecondaryColor(array &$scoreArray)
    {
        $maxSScore = 0;
        $secondaryIdx = 0;

        $primary = $scoreArray['clusters'][$scoreArray['primary']['idx']];

        array_walk($scoreArray['clusters'], function (&$c, $idx) use (&$maxSScore, &$secondaryIdx, $scoreArray, $primary) {
            if ($idx==$scoreArray['primary']['idx']) { // primary != secondary
                $c['s_score']=0;
                return;
            }
            [$sf, $vf, $cf] = $this->normalizeColor($c, $scoreArray);

            $distPrimary = $c['clusterObj']->getDistanceWith($primary['clusterObj']);

            $c['s_score'] = $sf * config('dominant-color.secondary.saturationMultiplier');
            $c['s_score'] += $vf * config('dominant-color.secondary.valueMultiplier');
            $c['s_score'] *= ($cf * config('dominant-color.secondary.countMultiplier') + $distPrimary * config('dominant-color.secondary.priDistanceMultiplier'));
            $c['s_score'] -= $c['p_score'] * config('dominant-color.secondary.priScoreDifferenceMultiplier');

            if ($sf < config('dominant-color.saturationLowThreshold')) {
                $c['s_score'] *= config('dominant-color.saturationLowMultiplier');
            }
            if ($vf < config('dominant-color.valueLowThreshold')) {
                $c['s_score'] *= config('dominant-color.valueLowMultiplier');
            }


            if ($c['s_score'] > $maxSScore) {
                $maxSScore = $c['s_score'];
                $secondaryIdx = $idx;
            }
        });
        $scoreArray['secondary'] = ['maxScore'=>$maxSScore, 'idx'=>$secondaryIdx];

        return new Color($scoreArray['clusters'][$secondaryIdx]);
    }

    private function normalizeColor(array $cluster, array $scoreArray): array
    {
        $sf = $cluster['s'] / $scoreArray['maxS'];
        $vf = $cluster['v'] / $scoreArray['maxV'];
        $cf = $cluster['count'] / $scoreArray['maxCount'];
        $sf *= $this->nlcurve($vf); //decrease saturation for dark colors

        return [$sf, $vf, $cf];
    }

    private function nlcurve($x)
    {
        // 0>0 0.1>0.05 0.5>0.75 0.8>0.95 1>1
        // 5.85317x^4−13.254x^3+8.6379x^2−0.237103x

        return ColorConversion::clamp(5.85317 * $x**4 - 13.254 * $x**3 + 8.6379 * $x**2 - 0.237103 * $x, 0, 1);
    }
}
