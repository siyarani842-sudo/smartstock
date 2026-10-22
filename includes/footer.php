<?php
/* =========================================================
   FOOTER (layout end)
   A page may set $chartData (array) before including this
   file; it is passed to script.js as window.SS_CHARTS.
========================================================= */

$flashes = get_flashes();
?>
                </section>

                <footer class="app-footer">
                    &copy; <?= date('Y') ?> <?= e(setting('company_name', 'SmartStock AI')) ?> &middot; Inventory Intelligence
                </footer>

            </div><!-- /.content -->

        </main>

    </div><!-- /.app -->


    <!-- =========================================================
         TOASTS (flash messages)
    ========================================================== -->
    <div class="toast-stack" id="toastStack">
        <?php foreach ($flashes as $flash): ?>
            <div class="toast show <?= e($flash['type']) ?>">
                <div class="toast-icon">
                    <i class="fa-solid <?= $flash['type'] === 'error' ? 'fa-xmark' : ($flash['type'] === 'warning' ? 'fa-exclamation' : 'fa-check') ?>"></i>
                </div>
                <div>
                    <strong><?= $flash['type'] === 'error' ? 'Error' : ($flash['type'] === 'warning' ? 'Warning' : 'Success') ?></strong>
                    <span><?= e($flash['message']) ?></span>
                </div>
                <button type="button" class="toast-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endforeach; ?>
    </div>


    <?php if (!empty($chartData)): ?>
        <script>
            window.SS_CHARTS = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        </script>
    <?php endif; ?>

    <script>
        window.SS_CURRENCY = <?= json_encode(setting('currency_symbol', '$')) ?>;
    </script>
    <script src="<?= e(url('assets/js/script.js')) ?>"></script>

</body>

</html>
