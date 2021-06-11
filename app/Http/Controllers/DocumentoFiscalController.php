<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
// use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;

class DocumentoFiscalController extends Controller
{

    public function initialDocument(string $rucEmpresa, string $rucEmisor)
    {
        $see = new See();
        $pfx = file_get_contents(base_path('certificates/'.$rucEmpresa.'/'.$rucEmisor.'/'.$rucEmisor.'.pfx'));
        $password = '123456';
        $certificate = new X509Certificate($pfx, $password);
        $see->setCertificate($certificate->export(X509ContentType::PEM));
        $see->setService(SunatEndpoints::FE_BETA);
        $see->setClaveSOL('20000000001', 'MODDATOS', 'moddatos');
        return $see;
    }

    public function generateInvoice(Request $request)
    {
        // Cliente
        $tipoDocCliente = $request->tipoDocumentoCliente;
        $numeroDocCliente = $request->numeroDocCliente;
        $razonSocialCliente = $request->razonSocialCliente;

        // Dirección Emisor
        $ubigeoId = $request->ubigeoId;
        $departamento = $request->departamento;
        $provincia = $request->provincia;
        $distrito = $request->distrito;
        $urbanizacion = $request->urbanizacion;
        $direccion = $request->direccion;
        $codigoLocal = $request->codigoLocal;
        
        // Emisor
        $rucEmpresa = $request->rucEmpresa;
        $rucEmisor = $request->rucEmisor;
        $razonSocialEmisor = $request->razonSocialEmisor;
        $nombreComercialEmisor = $request->nombreComercialEmisor;

        // Venta
        $tipoOperacion = $request->tipoOperacion;
        $tipoDocumento = $request->tipoDocumento;
        $serie = $request->serie;
        $correlativo = $request->correlativo;
        $fechaEmision = new DateTime();
        $tipoMoneda = $request->tipoMoneda;
        $montoOperacionGravadas = $request->montoOperacionGravadas;
        $montoIGV = $request->montoIGV;
        $totalImpuestos = $request->totalImpuestos;
        $valorVenta = $request->valorVenta;
        $subTotal = $request->subTotal;
        $montoImporteVenta = $request->montoImporteVenta;

        // Items
        $items = $request->items;

        $codigoLeyenda = $request->codigoLeyenda;
        $valorLeyenda = $request->valorLeyenda;

        
        $see = self::initialDocument($rucEmpresa, $rucEmisor);

        // Cliente
        $client = (new Client())
        ->setTipoDoc($tipoDocCliente) // 6
        ->setNumDoc($numeroDocCliente)
        ->setRznSocial($razonSocialCliente);

        // Emisor
        $address = (new Address())
        ->setUbigueo($ubigeoId) // '150101'
        ->setDepartamento($departamento)
        ->setProvincia($provincia)
        ->setDistrito($distrito)
        ->setUrbanizacion($urbanizacion)
        ->setDireccion($direccion)
        ->setCodLocal($codigoLocal) // Codigo de establecimiento asignado por SUNAT, 0000 por defecto.
        ;

        $company = (new Company())
        ->setRuc($rucEmisor)
        ->setRazonSocial($razonSocialEmisor)
        ->setNombreComercial($nombreComercialEmisor)
        ->setAddress($address)
        ;

        // Venta
        $invoice = (new Invoice())
        ->setUblVersion('2.1')
        ->setTipoOperacion($tipoOperacion) // 0101 Venta - Catalog. 51
        ->setTipoDoc($tipoDocumento) // Factura - Catalog. 01 
        ->setSerie($serie)
        ->setCorrelativo($correlativo)
        ->setFechaEmision($fechaEmision) // Zona horaria: Lima
        // ->setFormaPago(new FormaPagoContado()) // FormaPago: Contado
        ->setTipoMoneda($tipoMoneda) // PEN Sol - Catalog. 02
        ->setCompany($company)
        ->setClient($client)
        ->setMtoOperGravadas($montoOperacionGravadas)
        ->setMtoIGV($montoIGV)
        ->setTotalImpuestos($totalImpuestos)
        ->setValorVenta($valorVenta)
        ->setSubTotal($subTotal)
        ->setMtoImpVenta($montoImporteVenta)
        ;

        $detailInvoice = [];

        foreach ($items as $product) {
            $item = (new SaleDetail())
            ->setCodProducto($product['codigoProducto'])
            ->setUnidad($product['codigoUnidad']) // Unidad - Catalog. 03
            ->setCantidad($product['cantidad'])
            ->setMtoValorUnitario($product['montoValorUnitario'])
            ->setDescripcion($product['descripcion'])
            ->setMtoBaseIgv($product['montoBaseIgv'])
            ->setPorcentajeIgv(18.00) // 18%
            ->setIgv($product['igv'])
            ->setTipAfeIgv($product['tipAfeIgv']) // '10' Gravado Op. Onerosa - Catalog. 07
            ->setTotalImpuestos($product['totalImpuestos']) // Suma de impuestos en el detalle
            ->setMtoValorVenta($product['montoValorVenta'])
            ->setMtoPrecioUnitario($product['montoPrecioUnitario'])
            ;

            $detailInvoice[] = $item;
        }

        $legend = (new Legend())
        ->setCode($codigoLeyenda) // 1000 Monto en letras - Catalog. 52
        ->setValue($valorLeyenda); // 'SON DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES'

        $invoice->setDetails($detailInvoice)
            ->setLegends([$legend]);

        $result = $see->send($invoice);

        // Guardar XML firmado digitalmente.
        file_put_contents($invoice->getName().'.xml',
                            $see->getFactory()->getLastXml());
        
        // Verificamos que la conexión con SUNAT fue exitosa.
        if (!$result->isSuccess()) {
            // Mostrar error al conectarse a SUNAT.
            // echo 'Codigo Error: '.$result->getError()->getCode();
            // echo 'Mensaje Error: '.$result->getError()->getMessage();
            return response()->json([
                "Codigo Error" => $result->getError()->getCode(),
                "Mensaje Error" => $result->getError()->getMessage(),
            ], 200);
        }
        
        // Guardamos el CDR
        file_put_contents('R-'.$invoice->getName().'.zip', $result->getCdrZip());

        return response()->json([
            // "cdr" => $result,
            "xml" => $see->getFactory()->getLastXml(),
            // "data" => "hola"
        ], 200);
    }
}
