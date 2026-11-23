<?php
/**
 * Lo que ve quien abre un enlace que ya no sirve.
 *
 * Un enlace caducado, uno ya pagado y uno inventado dan todos esta
 * misma página. Distinguirlos le diría a quien está probando tokens
 * cuáles existen, que es justo lo que no debe saber.
 *
 * Por eso tampoco dice «caducado» ni «ya pagado»: dice que no está
 * disponible y ofrece la vía para resolverlo, que es lo único útil
 * tanto para el cliente legítimo como para nosotros.
 *
 * @var \CashMan\Http\Enrutador $rutas
 */
use CashMan\Http\Plantilla as P;
?>

<div class="enl">
  <div class="enl-tarjeta">
    <div class="enl-cuerpo text-center">
      <div class="enl-tic enl-tic--gris">!</div>

      <h1 class="enl-titulo">Este enlace ya no está disponible</h1>

      <p>
        Puede que ya se haya utilizado o que haya pasado su fecha de
        vigencia. Si todavía tiene un saldo pendiente, podemos generarle
        uno nuevo en el momento.
      </p>

      <p class="enl-nota">
        Escríbanos o llámenos y se lo enviamos de inmediato. Si ya
        efectuó el pago, no hace falta que haga nada más.
      </p>

      <a class="enl-boton enl-boton--claro"
         href="<?= P::attr($rutas->url('contacto')) ?>">
        Comunicarse con nosotros
      </a>
    </div>
  </div>
</div>
