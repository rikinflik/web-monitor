import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

import $ from 'jquery';
import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';

window.$ = window.jQuery = $;

const SPANISH = {
    emptyTable: 'No hay datos disponibles',
    info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
    infoEmpty: 'Mostrando 0 a 0 de 0 registros',
    infoFiltered: '(filtrado de _MAX_ registros totales)',
    lengthMenu: 'Mostrar _MENU_ registros',
    loadingRecords: 'Cargando...',
    processing: 'Procesando...',
    search: 'Buscar:',
    zeroRecords: 'No se encontraron resultados',
    paginate: {
        first: 'Primero',
        last: 'Último',
        next: 'Siguiente',
        previous: 'Anterior',
    },
    aria: {
        sortAscending: ': activar para ordenar la columna de forma ascendente',
        sortDescending: ': activar para ordenar la columna de forma descendente',
    },
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.js-datatable').forEach((table) => {
        // Columnas sin ordenación (marcadas con data-orderable="false" en el <th>).
        const columnDefs = [];
        table.querySelectorAll('thead th').forEach((th, index) => {
            if (th.dataset.orderable === 'false') {
                columnDefs.push({ orderable: false, targets: index });
            }
        });

        new DataTable(table, {
            language: SPANISH,
            pageLength: 25,
            order: [],
            columnDefs,
        });
    });
});
