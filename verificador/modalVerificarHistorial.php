<div class="modal fade" id="modalVerificarHistorial" tabindex="-1" aria-labelledby="modalVerificarHistorialLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark text-light border-0 shadow-lg" style="border-radius: 12px;">
            
            <div class="modal-header border-bottom border-secondary py-3 px-4">
                <h5 class="modal-title d-flex align-items-center gap-2" id="modalVerificarHistorialLabel">
                    <i class="bi bi-database-fill-lock text-warning fs-4"></i> 
                    <span>Comprobación cadena de hashes</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4 bg-secondary bg-opacity-10">
                <form action="asistencia.php" method="post" class="d-flex flex-column gap-3">
                        <?php
                        require "verificarHashesAsistencia.php";
                        if ($bConexion) {
                            $todo_correcto = verificarHashesAsistencia($oMysqli_audit);
                            if ( ($todo_correcto[0]) and (isset($todo_correcto[1])) ) {
                                $mensaje = "La cadena de bloques interna de auditoría ha sido procesada con éxito. No se han detectado discrepancias entre los registros históricos firmados y el estado actual de la tabla de asistencia.";
                            } else {
                                $mensaje = "El sistema ha detectado una ruptura en la cadena de confianza o una discrepancia de datos en el registro de auditoría.";
                            }
                        }
                        ?>

                    <div class="container my-0">
                        <div class="card shadow-sm">
                            <!-- Encabezado dinámico dependiendo del resultado criptográfico -->
                            <div class="card-header d-flex justify-content-between align-items-center <?php echo $todo_correcto[0] ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                <h5 class="mb-0">
                                    <i class="bi <?php echo $todo_correcto[0] ? 'bi-shield-check' : 'bi-shield-exclamation'; ?>"></i> 
                                    Resultado de la Verificación de Auditoría
                                </h5>
                                <span class="badge bg-light text-dark fw-bold">
                                    <?php echo count($todo_correcto[1]); ?> Registros procesados
                                </span>
                            </div>
                            
                            <div class="card-body bg-light">
                                <?php if (empty($todo_correcto[1])): ?>
                                    <div class="alert alert-warning mb-0" role="alert">
                                        No se encontraron registros de logs en la base de datos para verificar.
                                    </div>
                                <?php else: ?>
                                    <!-- CONTENEDOR CON SCROLL: Controla la altura máxima y activa barras de desplazamiento -->
                                    <div class="overflow-auto border rounded bg-white" style="max-height: 400px;">
                                        <div class="list-group list-group-flush font-monospace" style="font-size: 0.875rem;">
                                            <?php foreach ($todo_correcto[1] as $index => $log): ?>
                                                <div class="list-group-item list-group-item-action p-3">
                                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                                        <strong class="text-secondary">Cadena #<?php echo $index + 1; ?></strong>
                                                        <span class="badge rounded-pill bg-info text-dark">OK</span>
                                                    </div>
                                                    <!-- text-wrap evita que el string largo de los hashes rompa el contenedor horizontalmente -->
                                                    <p class="mb-0 text-wrap text-break lh-sm">
                                                        <?php echo htmlspecialchars($log); ?>
                                                    </p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-footer text-muted text-end small">
                                Estado global de la cadena: <strong><?php echo $todo_correcto[0] ? 'INTEGRA / VERIFICADA' : 'CADENA ROTA O MODIFICADA'; ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="mt-1" style="font-size: 0.8rem; line-height: 1.3;">
                        <i class="bi bi-info-circle-fill text-info"></i> <?php echo $mensaje; ?>
                    </div>
                    
                    <hr class="border-secondary my-2">

                    <div class="d-flex gap-2 justify-content-end mt-2">
                        <button type="button" class="btn btn-outline-secondary text-light border-secondary px-4 fw-bold" data-bs-dismiss="modal">
                            Salir
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>