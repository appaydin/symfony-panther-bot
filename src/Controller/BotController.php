<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class BotController
 *
 * @author Kerem ENDER <apaydin541@gmail.com>
 */
class BotController extends AbstractController
{
    /**
     * Item Link List
     *
     * @var array
     */
    private $linkList = [];

    /**
     * Item Data
     *
     * @var array
     */
    private $itemData = [];

    /**
     * @var Client
     */
    private $client;

    /**
     * Homepage
     *
     * @Route(name="homepage", path="/")
     *
     * @return Response
     */
    public function home(): Response
    {
        return $this->render('base.html.twig');
    }

    /**
     * Generate Items Link
     *
     * @Route(name="bot_fetch_links", path="/links")
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getLinks(Request $request): JsonResponse
    {
        $fetchLink = $request->get('link');
        if (!$fetchLink) {
            throw $this->createNotFoundException();
        }

        $this->createClient();

        do {
            // Fetch Page
            $crawler = $this->client->request('GET', $fetchLink);

            // Fetch Item Links
            $this->linkList = array_merge($this->linkList, $crawler->filter('.slisttable h4 a')->each(static function (Crawler $node, $i) {
                return $node->getAttribute('href');
            }));

            // Get Next Page Link
            $pagination = $crawler->filter('.pagination li:not(.active):last-child a');
            $fetchLink = $pagination->getElement(0) ? $pagination->getAttribute('href') : null;
        } while (null !== $fetchLink);

        // Remove Unique
        array_unique($this->linkList);
        $this->linkList = array_reverse($this->linkList);

        // Save JSON
        file_put_contents($this->getParameter('kernel.project_dir') . '/data/items.json', json_encode($this->linkList, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // Json Response
        return new JsonResponse([
            'count' => count($this->linkList),
            'message' => 'Success',
            'file_path' => 'data/items.json'
        ]);
    }

    /**
     * Get Item Content to JSON
     *
     * @Route(name="bot_fetch_item", path="/items")
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getItem(Request $request): JsonResponse
    {
        $items = null;

        // Load Items Link
        if ($request->get('link')) {
            $items = [$request->get('link')];
        } else {
            $path = $this->getParameter('kernel.project_dir') . '/data/items.json';
            if (file_exists($path)) {
                $items = json_decode(file_get_contents($path), true);
            }
        }

        // Not Items
        if (!$items) {
            throw $this->createNotFoundException();
        }

        $this->createClient();

        // Fetch Links
        foreach ($items as $link) {
            $crawler = $this->client->request('GET', $link);

            // Find Map
            $map = $crawler->filter('.single-map script:not([src])');
            if ($map->getElement(0)) {
                preg_match('/latLng:.*\[(.+?)\]/', $map->html(), $map);

                if (isset($map[1])) {
                    $map = explode(', ', $map[1]);
                }
            }

            $category = explode(' ', $crawler->filter('.single_prop_type .sptext')->text(), 2);

            // Parse Data
            $this->itemData[] = [
                'title' => $crawler->filter('h1.single-title')->getText(),
                'body' => trim($crawler->filter('.context-content')->getAttribute('innerHTML')),
                'images' => $crawler->filter('img.rsMainSlideImage[src]')->each(static function (Crawler $node, $i) {
                    return strpos($node->getAttribute('src'), 'default-photo') === false ? $node->getAttribute('src') : null;
                }),
                'map' => count($map) === 2 ? ['lat' => $map[0], 'lng' => $map[1]] : null,
                'price' => str_replace('.', '', $crawler->filter('.single-price')->text()),
                'currency' => 'TRY',
                'address' => $crawler->filter('.single_prop_adress .sptext')->text(),
                'category' => $this->matchCategory($category[0]),
                'type' => $this->matchType($category[1]),
                'country' => 'TR',
                'location' => explode(' / ', $crawler->filter('.single_prop_country .sptext')->text(), 2),
                'property' => $this->matchProperty($category[1], $crawler)
            ];
        }

        // Save JSON
        file_put_contents($this->getParameter('kernel.project_dir') . '/data/data.json', json_encode($this->itemData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return new JsonResponse([
            'count' => count($this->itemData),
            'message' => 'Success',
            'file_path' => 'data/data.json'
        ]);
    }

    /**
     * Create Chrome Client
     */
    private function createClient(): void
    {
        if (!$this->client) {
            set_time_limit(0);
            $this->client = Client::createChromeClient();
        }
    }

    /**
     * Match Category
     *
     * @param string|null $category
     *
     * @return string
     */
    private function matchCategory(?string $category): string
    {
        if ($category === 'Kiralık') {
            return 'rent';
        }

        return 'sale';
    }

    /**
     * Match Category Type
     *
     * @param string|null $type
     *
     * @return string
     */
    private function matchType(?string $type): string
    {
        $result = 'apartment';

        switch ($type) {
            case 'Arsa':
            case 'Bahçe':
            case 'Arazi':
            case 'Tarla':
            case 'Çiftlik':
                $result = 'land';
                break;
            case 'Müstakil Ev':
                $result = 'house';
                break;
            case 'İş Yeri':
                $result = 'office';
                break;
            case 'Dükkan':
                $result = 'store';
                break;
            case 'Villa':
                $result = 'villa';
                break;
            case 'Yayla Evi':
            case 'Deniz Evi':
                $result = 'summery';
                break;
            case 'Apart':
            case 'Konut':
                $result = 'apartment';
                break;
            case 'Petrol':
            case 'Fabrika':
                $result = 'factory';
                break;
        }

        return $result;
    }

    private function matchProperty($type, Crawler $crawler): array
    {
        if (in_array($type, ['Arsa', 'Bahçe', 'Arazi', 'Tarla', 'Çiftlik', 'Arazi'])) {
            $data = [];

            if ($crawler->filter('div[class*=Acik_Alan_m2] .sptext')->getElement(0)) $data['square_feet'] = $crawler->filter('div[class*=Acik_Alan_m2] .sptext')->getText();
            if ($crawler->filter('div[class*=Metre_Kare] .sptext')->getElement(0)) $data['square_feet'] = $crawler->filter('div[class*=Metre_Kare] .sptext')->getText();
            if ($crawler->filter('div[class*=Ada_No] .sptext')->getElement(0)) $data['ada_no'] = $crawler->filter('div[class*=Ada_No] .sptext')->getText();
            if ($crawler->filter('div[class*=Parsel_No] .sptext')->getElement(0)) $data['parsel_no'] = $crawler->filter('div[class*=Parsel_No] .sptext')->getText();
            if ($crawler->filter('div[class*=Pafta_No] .sptext')->getElement(0)) $data['pafta_no'] = $crawler->filter('div[class*=Pafta_No] .sptext')->getText();
            if ($crawler->filter('div[class*=Gabari] .sptext')->getElement(0)) $data['gabari'] = $crawler->filter('div[class*=Gabari] .sptext')->getText();
            if ($crawler->filter('div[class*=Krediye_Uygunluk] .sptext')->getElement(0)) $data['credit_available'] = $crawler->filter('div[class*=Krediye_Uygunluk] .sptext')->getText();
            if ($crawler->filter('div[class*=Imar_Durumu] .sptext')->getElement(0)) $data['imar_durumu'] = $crawler->filter('div[class*=Imar_Durumu] .sptext')->getText();
            if ($crawler->filter('div[class*=Tapu_Durumu] .sptext')->getElement(0)) $data['tapu_durumu'] = $crawler->filter('div[class*=Tapu_Durumu] .sptext')->getText();

            if (isset($data['credit_available'])) $data['credit_available'] = $data['credit_available'] === 'Evet' ? 'yes' : 'no';
            if (isset($data['imar_durumu'])){
                switch ($data['imar_durumu']){
                    case 'Ada': $data['imar_durumu'] = 'hisseli'; break;
                    case 'Bağ & Bahçe': $data['imar_durumu'] = 'bagbahce'; break;
                    case 'Depo & Antrepo': $data['imar_durumu'] = 'depoantrepo'; break;
                    case 'Eğitim': $data['imar_durumu'] = 'egitim'; break;
                    case 'Enerji Depolama': $data['imar_durumu'] = 'enerjidepo'; break;
                    case 'Konut': $data['imar_durumu'] = 'konut'; break;
                    case 'Muhtelif': $data['imar_durumu'] = 'muhtelif'; break;
                    case 'Özel Kullanım': $data['imar_durumu'] = 'ozel'; break;
                    case 'Sağlık': $data['imar_durumu'] = 'saglik'; break;
                    case 'Sanayi': $data['imar_durumu'] = 'sanayi'; break;
                    case 'Sit Alanı': $data['imar_durumu'] = 'sitalani'; break;
                    case 'Spor Alanı': $data['imar_durumu'] = 'sporalani'; break;
                    case 'Tarla': $data['imar_durumu'] = 'tarla'; break;
                    case 'Ticari': $data['imar_durumu'] = 'ticari'; break;
                    case 'Ticari Konut': $data['imar_durumu'] = 'ticarikonut'; break;
                    case 'Toplu Konut': $data['imar_durumu'] = 'toplukonut'; break;
                    case 'Villa': $data['imar_durumu'] = 'villa'; break;
                    case 'Zeytinlik': $data['imar_durumu'] = 'zeytinlik'; break;
                }
            }
            if (isset($data['tapu_durumu'])){
                switch ($data['tapu_durumu']){
                    case 'Hisseli Tapu': $data['tapu_durumu'] = 'hisseli'; break;
                    case 'Müstakil Parsel': $data['tapu_durumu'] = 'mustakil'; break;
                    case 'Tahsis': $data['tapu_durumu'] = 'tahsis'; break;
                    case 'Zilliyet': $data['tapu_durumu'] = 'zilliyet'; break;
                }
            }
            return $data;
        }

        if (in_array($type, ['Konut', 'Apart', 'Müstakil Ev', 'Yayla Evi', 'Deniz Evi'])) {
            $data = [];

            if ($crawler->filter('div[class*=Metre_Kare] .sptext')->getElement(0)) $data['square_feet'] = $crawler->filter('div[class*=Metre_Kare] .sptext')->getText();
            if ($crawler->filter('div[class*=Oda_Sayisi] .sptext')->getElement(0)) $data['room_count'] = $crawler->filter('div[class*=Oda_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Banyo_Sayisi] .sptext')->getElement(0)) $data['bathroom_count'] = $crawler->filter('div[class*=Banyo_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Bina_Yasi] .sptext')->getElement(0)) $data['building_age'] = $crawler->filter('div[class*=Bina_Yasi] .sptext')->getText();
            if ($crawler->filter('div[class*=Kat_Sayisi] .sptext')->getElement(0)) $data['building_floors'] = $crawler->filter('div[class*=Kat_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Bulundugu_Kat] .sptext')->getElement(0)) $data['floor_location'] = $crawler->filter('div[class*=Bulundugu_Kat] .sptext')->getText();
            if ($crawler->filter('div[class*=Isitma] .sptext')->getElement(0)) $data['heating'] = $crawler->filter('div[class*=Isitma] .sptext')->getText();
            if ($crawler->filter('div[class*=Esyali] .sptext')->getElement(0)) $data['furnished'] = $crawler->filter('div[class*=Esyali] .sptext')->getText();
            if ($crawler->filter('div[class*=Kullanim_Durumu] .sptext')->getElement(0)) $data['use_status'] = $crawler->filter('div[class*=Kullanim_Durumu] .sptext')->getText();
            if ($crawler->filter('div[class*=Krediye_Uygun] .sptext')->getElement(0)) $data['credit_available'] = $crawler->filter('div[class*=Krediye_Uygun] .sptext')->getText();

            if (isset($data['credit_available'])) $data['credit_available'] = $data['credit_available'] === 'Evet' ? 'yes' : 'no';
            if (isset($data['furnished'])) $data['furnished'] = $data['furnished'] === 'Evet' ? 'yes' : 'no';
            if (isset($data['heating'])) {
                switch ($data['heating']){
                    case 'Belirtilmemiş': $data['heating'] = 'no-heat'; break;
                    case 'Soba': $data['heating'] = 'stove'; break;
                    case 'Doğalgaz Sobası': $data['heating'] = 'gas-stove'; break;
                    case 'Kat Kalöriferi': $data['heating'] = 'kk-coal'; break;
                    case 'Merkezi Sistemi': $data['heating'] = 'cc-coal'; break;
                    case 'Merkezi Sistem (Isı Pay Ölçer)': $data['heating'] = 'cc-meter'; break;
                    case 'Doğalgaz (Kombi)': $data['heating'] = 'gas-combi'; break;
                    case 'Yerden Isıtma': $data['heating'] = 'uf-heat'; break;
                    case 'Klima': $data['heating'] = 'air-cond'; break;
                    case 'Güneş Enerjisi': $data['heating'] = 'solar-energy'; break;
                    case 'Jeotermal': $data['heating'] = 'jeotermal'; break;
                }
            }
            if (isset($data['use_status'])) {
                switch ($data['use_status']){
                    case 'Boş': $data['use_status'] = 'empty'; break;
                    case 'Kiracılı': $data['use_status'] = 'tenant'; break;
                    case 'Ev Sahibi': $data['use_status'] = 'landlord'; break;
                }
            }
            if (isset($data['building_age'])) {
                $export = explode('-', $data['building_age']);
                if (is_array($export) && count($export) > 1){
                    $data['building_age'] = $export[0];
                }
            }

            return $data;
        }

        if (in_array($type, ['İş Yeri', 'Dükkan'])) {
            $data = [];

            if ($crawler->filter('div[class*=Metre_Kare] .sptext')->getElement(0)) $data['square_feet'] = $crawler->filter('div[class*=Metre_Kare] .sptext')->getText();
            if ($crawler->filter('div[class*=Bolum_Oda_Sayisi] .sptext')->getElement(0)) $data['room_count'] = $crawler->filter('div[class*=Bolum_Oda_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Isitma] .sptext')->getElement(0)) $data['heating'] = $crawler->filter('div[class*=Isitma] .sptext')->getText();
            if ($crawler->filter('div[class*=Bina_Yasi] .sptext')->getElement(0)) $data['building_age'] = $crawler->filter('div[class*=Bina_Yasi] .sptext')->getText();

            if (isset($data['heating'])) {
                switch ($data['heating']){
                    case 'Belirtilmemiş': $data['heating'] = 'no-heat'; break;
                    case 'Soba': $data['heating'] = 'stove'; break;
                    case 'Doğalgaz Sobası': $data['heating'] = 'gas-stove'; break;
                    case 'Kat Kalöriferi': $data['heating'] = 'kk-coal'; break;
                    case 'Merkezi Sistemi': $data['heating'] = 'cc-coal'; break;
                    case 'Merkezi Sistem (Isı Pay Ölçer)': $data['heating'] = 'cc-meter'; break;
                    case 'Doğalgaz (Kombi)': $data['heating'] = 'gas-combi'; break;
                    case 'Yerden Isıtma': $data['heating'] = 'uf-heat'; break;
                    case 'Klima': $data['heating'] = 'air-cond'; break;
                    case 'Güneş Enerjisi': $data['heating'] = 'solar-energy'; break;
                    case 'Jeotermal': $data['heating'] = 'jeotermal'; break;
                }
            }
            if (isset($data['building_age'])) {
                $export = explode('-', $data['building_age']);
                if (is_array($export) && count($export) > 1){
                    $data['building_age'] = $export[0];
                }
            }

            return $data;
        }

        if (in_array($type, ['Fabrika', 'Petrol'])) {
            $data = [];

            if ($crawler->filter('div[class*=Acik_Alan_m2] .sptext')->getElement(0)) $data['square_feet_open'] = $crawler->filter('div[class*=Metre_Kare] .sptext')->getText();
            if ($crawler->filter('div[class*=Kapali_Alan_m2] .sptext')->getElement(0)) $data['square_feet_close'] = $crawler->filter('div[class*=Oda_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Bina_Adedi] .sptext')->getElement(0)) $data['building_count'] = $crawler->filter('div[class*=Banyo_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Bolum_Oda_Sayisi] .sptext')->getElement(0)) $data['room_count'] = $crawler->filter('div[class*=building_age] .sptext')->getText();
            if ($crawler->filter('div[class*=Kat_Sayisi_] .sptext')->getElement(0)) $data['number_floors'] = $crawler->filter('div[class*=Kat_Sayisi] .sptext')->getText();
            if ($crawler->filter('div[class*=Binanin_Yasi_] .sptext')->getElement(0)) $data['building_age'] = $crawler->filter('div[class*=Bulundugu_Kat] .sptext')->getText();
            if ($crawler->filter('div[class*=Isinma_Tipi] .sptext')->getElement(0)) $data['heating'] = $crawler->filter('div[class*=Isitma] .sptext')->getText();

            if (isset($data['heating'])) {
                switch ($data['heating']){
                    case 'Belirtilmemiş': $data['heating'] = 'no-heat'; break;
                    case 'Soba': $data['heating'] = 'stove'; break;
                    case 'Doğalgaz Sobası': $data['heating'] = 'gas-stove'; break;
                    case 'Kat Kalöriferi': $data['heating'] = 'kk-coal'; break;
                    case 'Merkezi Sistemi': $data['heating'] = 'cc-coal'; break;
                    case 'Merkezi Sistem (Isı Pay Ölçer)': $data['heating'] = 'cc-meter'; break;
                    case 'Doğalgaz (Kombi)': $data['heating'] = 'gas-combi'; break;
                    case 'Yerden Isıtma': $data['heating'] = 'uf-heat'; break;
                    case 'Klima': $data['heating'] = 'air-cond'; break;
                    case 'Güneş Enerjisi': $data['heating'] = 'solar-energy'; break;
                    case 'Jeotermal': $data['heating'] = 'jeotermal'; break;
                }
            }
            if (isset($data['building_age'])) {
                $export = explode('-', $data['building_age']);
                if (is_array($export) && count($export) > 1){
                    $data['building_age'] = $export[0];
                }
            }

            return $data;
        }

        return [];
    }
}