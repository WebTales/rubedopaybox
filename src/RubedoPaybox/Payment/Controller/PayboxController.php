<?php
/**
 * Rubedo -- ECM solution
 * Copyright (c) 2014, WebTales (http://www.webtales.fr/).
 * All rights reserved.
 * licensing@webtales.fr
 *
 * Open Source License
 * ------------------------------------------------------------------------------------------
 * Rubedo is licensed under the terms of the Open Source GPL 3.0 license. 
 *
 * @category   Rubedo
 * @package    Rubedo
 * @copyright  Copyright (c) 2012-2014 WebTales (http://www.webtales.fr)
 * @license    http://www.gnu.org/licenses/gpl.html Open Source GPL 3.0 license
 */
namespace RubedoPaybox\Payment\Controller;
use Rubedo\Services\Manager;
use Rubedo\Payment\Controller\AbstractController;
use WebTales\MongoFilters\Filter;
use Zend\View\Model\JsonModel;

/**
 *
 * @category Rubedo
 * @package Rubedo
 */
class PayboxController extends AbstractController
{
    public function __construct()
    {
        $this->paymentMeans = 'paybox';
        parent::__construct();
    }


    public function indexAction ()
    {
        $serverUrl = $this->getRequest()
                ->getUri()
                ->getScheme() . '://' . $this->getRequest()
                ->getUri()
                ->getHost();

        $this->initOrder();
        $amount = $this->getOrderPrice();
        $currentUser = Manager::getService('CurrentUser')->getCurrentUser();

        $output = $this->nativePMConfig;
        $output['pbxTotal'] = 100 * $amount;
        $output['ipnRoute'] = $serverUrl . $this->url()->fromRoute('payment', array(
                'controller' => 'paybox',
                'action' => 'ipn',
            ));
        $output['orderNumber'] = $this->currentOrder['orderNumber'];
        $output['emailUser'] = $currentUser['email'];
        $output['date'] = date('c');
        $output['format'] = 'order:R;erreur:E;carte:C;numauto:A;numtrans:S;numabo:B;montantbanque:M;sign:K';

        $param = 'PBX_SITE=' . $output['payboxSiteId'];
        $param .= '&PBX_RANG=' . $output['payboxRank'];
        $param .= '&PBX_IDENTIFIANT=' . $output['payboxId'];
        $param .= '&PBX_TOTAL=' . $output['pbxTotal'];
        $param .= '&PBX_DEVISE=978';
        $param .= '&PBX_REPONDRE_A=' . $output['ipnRoute'];
        $param .= '&PBX_EFFECTUE=' . $output['urlReturn'];
        $param .= '&PBX_REFUSE=' . $output['urlReturn'];
        $param .= '&PBX_ANNULE=' . $output['urlReturn'];
        $param .= '&PBX_CMD=' . $output['orderNumber'];
        $param .= '&PBX_PORTEUR=' . $output['emailUser'];
        $param .= '&PBX_RETOUR=' . $output['format'];
        $param .= '&PBX_HASH=SHA512';
        $param .= '&PBX_TIME=' . $output['date'];
        $binKey = pack("H*", $output['payboxKey']);
        $hmac = strtoupper(hash_hmac('sha512', $param, $binKey));
        $output['hmac'] = $hmac;
        $template = Manager::getService('FrontOfficeTemplates')->getFileThemePath("@RubedoPaybox/payboxSubmit.html.twig");
        return $this->sendResponse($output, $template);
    }

    public function ipnAction ()
    {
        $get = $this->params()->fromQuery();
        if (empty($get['order']) || empty($get['sign'])) {
            return new JsonModel(
                array(
                    'success' => false,
                    'message' => 'Order or sign empty'
                )
            );
        }
        $orderId = $get['order'];
        $pos_qs = strpos($_SERVER['REQUEST_URI'], '?');
        $pos_sign = strpos($_SERVER['REQUEST_URI'], '&sign=');
        $data = substr($_SERVER['REQUEST_URI'], $pos_qs + 1, $pos_sign - $pos_qs - 1);
        $sign = substr($_SERVER['REQUEST_URI'], $pos_sign + 6);

        $filter = Filter::factory()->addFilter(Filter::factory('Value')->setName('orderNumber')->setValue($orderId));
        $order = $this->ordersService->findOne($filter);
        if (empty($order)) {
            return new JsonModel(
                array(
                    'success' => false,
                    'message' => 'Order not found'
                )
            );
        }
        if (empty($get['erreur'])) {
            return new JsonModel(
                array(
                    'success' => true,
                )
            );
        }

        if ($get['erreur'] == '00000') {
            $filedata = file_get_contents(realpath(dirname(__FILE__) . '/../certs/paybox.pem'));
            $key = openssl_pkey_get_public($filedata);
            $decoded_sign = base64_decode(urldecode($sign));
            $verif_sign = openssl_verify($data, $decoded_sign, $key);

            if ($verif_sign != 1) {
                return new JsonModel(
                    array(
                        'success' => false,
                        'message' => 'KO Signature',
                    )
                );
            }
            if ((int) (100 * $order['finalPrice']) != (int) $get['montantbanque']) {
                return new JsonModel(
                    array(
                        'success' => false,
                        'message' => 'Amount incorrect',
                    )
                );
            }
            $order['status'] = 'Payé';
            $this->ordersService->update($order);
            return new JsonModel(
                array(
                    'success' => true,
                )
            );
        }
        return new JsonModel(
            array(
                'success' => false,
                'message' => $this->getErrorMsg($get['erreur']),
            )
        );
    }

    protected function getErrorMsg($codeErreur) {
        switch ($codeErreur) {
            case '00000':
                return 'Opération réussie.';
                break;
            case '00001':
                return 'La connexion au centre d\'autorisation a échoué. Vous pouvez dans ce cas là effectuer les redirections des internautes vers le FQDN';
                break;
            case '00002':
                return 'Une erreur de cohérence est survenue.';
                break;
            case '00003':
                return 'Erreur Paybox.';
                break;
            case '00004':
                return 'Numéro de porteur ou crytogramme visuel invalide.';
                break;
            case '00006':
                return 'Accès refusé ou site/rang/identifiant incorrect.';
                break;
            case '00008':
                return 'Date de fin de validité incorrecte.';
                break;
            case '00009':
                return 'Erreur de création d\'un abonnement.';
                break;
            case '00010':
                return 'Devise inconnue.';
                break;
            case '00011':
                return 'Montant incorrect.';
                break;
            case '00015':
                return 'Paiement déjà effectué';
                break;
            case '00016':
                return 'Abonné déjà existant (inscription nouvel abonné). Valeur \'U\' de la variable PBX_RETOUR.';
                break;
            case '00021':
                return 'Carte non autorisée.';
                break;
            case '00029':
                return 'Carte non conforme. Code erreur renvoyé lors de la documentation de la variable « PBX_EMPREINTE ».';
                break;
            case '00030':
                return 'Temps d\'attente > 15 mn par l\'internaute/acheteur au niveau de la page de paiements.';
                break;
            case '00031':
            case '00032':
                return 'Réservé';
                break;
            case '00033':
                return 'Code pays de l\'adresse IP du navigateur de l\'acheteur non autorisé.';
                break;
            // Nouveaux codes : 11/2013 (v6.1)
            case '00040':
                return 'Opération sans authentification 3-DSecure, bloquée par le filtre';
                break;
            case '99999':
                return 'Opération en attente de validation par l\'emmetteur du moyen de paiement.';
                break;
            default:
                if (substr($codeErreur, 0, 3) == '001')
                    return 'Paiement refusé par le centre d\'autorisation. En cas d\'autorisation de la transaction par le centre d\'autorisation de la banque, le code erreur \'00100\' sera en fait remplacé directement par \'00000\'.';
                else
                    return 'Pas de message';
                break;
        }
    }
}
