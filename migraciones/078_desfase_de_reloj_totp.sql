-- -----------------------------------------------------------------
-- 078. El desfase de reloj del teléfono, medido y guardado
-- -----------------------------------------------------------------
--
-- EL PROBLEMA
--
-- Los códigos de un solo uso se calculan a partir de la HORA. Si el
-- reloj del teléfono y el del servidor no coinciden, los números no
-- coinciden tampoco, y el sistema rechaza un código que la aplicación
-- del cliente muestra como bueno.
--
-- Pasó con tres minutos de diferencia: la aplicación mostraba 961012,
-- que era el código correcto… de tres minutos antes. La ventana de
-- tolerancia es de treinta segundos a cada lado —y tiene que serlo,
-- porque ensancharla es regalarle tiempo a quien robe un código— así
-- que la respuesta fue «el código no es válido».
--
-- Decirle al cliente «ponga su teléfono en hora» es correcto y es
-- inútil: la mitad no sabrá cómo, y el banco habrá perdido a alguien
-- que quería proteger su cuenta.
--
-- LA SOLUCIÓN
--
-- Medir el desfase UNA VEZ, cuando el cliente confirma el alta. En ese
-- momento ya está autenticado y el código es de su propio secreto
-- recién creado, así que se puede buscar en una ventana amplia sin
-- regalar nada: no hay atacante al que ayudar.
--
-- Lo que se mide se guarda aquí, y a partir de entonces el login
-- compara contra la hora del teléfono —la suya, la que de verdad usa—
-- con la misma ventana estrecha de treinta segundos de siempre.
--
-- Es lo que hace la propia aplicación de Google con su «corrección de
-- hora para los códigos», pero del lado del banco, que es donde
-- funciona para todo el mundo sin que nadie tenga que tocar nada.
--
-- LO QUE NO SE DEBILITA
--
-- La ventana de verificación sigue siendo de un periodo a cada lado.
-- Un código robado sigue valiendo noventa segundos y ni uno más, y
-- sigue sin poder reutilizarse. Lo único que cambia es desde dónde se
-- cuentan esos noventa segundos.
-- -----------------------------------------------------------------

ALTER TABLE usuarios
  ADD COLUMN totp_desfase_seg SMALLINT NOT NULL DEFAULT 0
      COMMENT 'Segundos que el reloj del telefono va adelantado (+) o atrasado (-)'
      AFTER totp_ultimo_contador;
