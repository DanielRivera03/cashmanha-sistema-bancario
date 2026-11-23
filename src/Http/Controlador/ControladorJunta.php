<?php

declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Aplicacion\Servicio\ServicioIndicadoresJunta;
use CashMan\Aplicacion\Servicio\ServicioSeriesJunta;
use CashMan\Infraestructura\Seguridad\Autorizacion;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * El tablero de la Junta Directiva.
 *
 * Una sola pantalla y un solo verbo: mirar. No hay POST, no hay
 * formularios y no hay parámetros que el usuario controle — todas las
 * consultas del servicio son fijas. Es deliberado: una vista de
 * gobierno que permita tocar algo deja de ser una vista de gobierno.
 */
final class ControladorJunta extends ControladorBase
{
    public function tablero(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('junta.ver');

        $this->render('junta/tablero', [
            'titulo'     => 'Junta Directiva · CashMan H.A.',
            'indicadores' => (new ServicioIndicadoresJunta())->todo(),
        ]);
    }

    /**
     * Cómo llegó el banco hasta aquí.
     *
     * El tablero dice cómo está el banco hoy; esto dice de dónde
     * viene. Son dos preguntas distintas y por eso son dos pantallas:
     * meter diez gráficos debajo de los indicadores haría una página
     * que nadie termina de leer.
     *
     * El único parámetro es el grano —día, semana, mes o año— y el
     * servicio lo valida contra su lista antes de usarlo, así que lo
     * que llegue por la URL no puede acabar en una consulta.
     */
    public function evolucion(array $parametros = []): void
    {
        Sesion::exigirAutenticado();
        Autorizacion::exigir('junta.ver');

        $periodo = ServicioSeriesJunta::valido(trim($this->get('p')));

        $this->render('junta/evolucion', [
            'titulo'     => 'Evolución · Junta Directiva · CashMan H.A.',
            'encabezado' => 'Junta Directiva · Evolución',
            'series'     => (new ServicioSeriesJunta())->todo($periodo),
            'periodos'   => ServicioSeriesJunta::periodos(),
            'periodo'    => $periodo,
        ]);
    }
}
