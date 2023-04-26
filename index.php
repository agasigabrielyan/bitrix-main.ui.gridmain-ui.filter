<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');

/** @var \CMain $APPLICATION */
use Bitrix\Main\UI\Extension;
Extension::load("ui.bootstrap4");
?>
<?php
    // укажем константы статусов задач, для того, чтобы переопределить идентификаторы, полученные из таблицы b_tasks на эти значения
    $taskStatuses = [
        '-3' => 'Задача почти просрочена',
        '-2' => 'Новая задача (не просмотрена)',
        '-1' => 'Задача просрочена',
         '1' => 'Новая задача',
         '2' => 'Задача принята ответственным',
         '3' => 'Задача выполняется',
         '4' => 'Условно завершена (ждет контроля постановщиком)',
         '5' => 'Задача завершена',
         '6' => 'Задача отложена',
         '7' => 'Задача отклонена ответственным'
    ];

    // укажем константы приоритетов, для того, чтобы переопределить идентификаторы приоритетов, полученные из таблицы b_tasks на эти значения
    $priorityStatuses = [
        '0' => 'Низкий приоритет',
        '1' => 'Средний',
        '2' => 'Высокий'
    ];

    // запишем в переменную идентификатор будущего грида
    $report_list = "report_list";


    $grid_options = new \Bitrix\Main\Grid\Options( $report_list );
    $sort = $grid_options->getSorting([
        'sort' => ['CREATED_DATE' => 'ASC'],
        'vars' => ['by'=>'by', 'order' => 'order']
    ]);
    $nav_params = $grid_options->GetNavParams();
    $nav = new \Bitrix\Main\UI\PageNavigation($report_list);
    $nav->allowAllRecords(true)
        ->setPageSize($nav_params['nPageSize'])
        ->initFromUri();

    $filterOption = new \Bitrix\Main\UI\Filter\Options( $report_list );
    $filterData = $filterOption->getFilter([]);
    $filter = [];

    foreach( $filterData as $key => $value ) {
        // фильтр по названию задачи
        if( $key === "TITLE" ) {
            $filter['TITLE'] = '%' . $filterData['TITLE'] . '%';
        }

        // фильтр по идентификатору задачи
        if( $key === "ID" ) {
            $filter['ID'] = $filterData['ID'];
        }

        // фильтр по ответственному задачи
        if( $key === "RESPONSIBLE_FULL_NAME" ) {
            $responsibleId = preg_replace("/U/","",$value);
        }

        if( $responsibleId>0 ) {
            $filter['RESPONSIBLE_ID'] = [$responsibleId];
        }

        if( strpos($key,"_from",) ) {
            $newKeyName = substr($key,"0",strpos($key, "_from"));
            $filter[">=" . $newKeyName] = $value;
        }
        if( strpos($key,"_to",) ) {
            $newKeyName = substr($key,"0",strpos($key, "_to"));
            $filter["<=" . $newKeyName] = $value;
        }
    }

    $tasks = \Bitrix\Tasks\TaskTable::getList([
        'filter' => $filter,
        'select' => [
            '*',
            'RESPONSIBLE_FULL_NAME',
            'RESPONSIBLE_ID'
        ],
        'offset' => $nav->getOffset(),
        'limit' => $nav->getLimit(),
        'order' => $sort['sort'],
        'runtime' => [
            'userdata' => [
                'data_type' => \Bitrix\Main\UserTable::getEntity(),
                'reference' => [
                        'this.RESPONSIBLE_ID' => 'ref.ID'
                ]
            ],
            new \Bitrix\Main\Entity\ExpressionField('RESPONSIBLE_FULL_NAME', 'CONCAT(coalesce(%s), " ", coalesce(%s))',['userdata.NAME','userdata.LAST_NAME']),
        ]
    ]);

    // рассчитаем общее количество задач в соответствии с фильтром
    $totalRowsCount = \Bitrix\Tasks\TaskTable::getList([
        'select' => ['COUNT'],
        'filter' => $filter,
        'runtime' => [
            new \Bitrix\Main\Entity\ExpressionField('COUNT', 'COUNT(%s)', ['ID'])
        ]
    ])->fetch()['COUNT'];

    // установим в объект навигаации общее количество элементов в соответствии с фильтром
    $nav->setRecordCount( $totalRowsCount );

    // соберем элементы для передачи в ключ data rows, чтобы передать в ROWS в гриде
    while( $task = $tasks->fetch() )
    {
        // зададим статусы задач, на основании констант
        $task['STATUS_NAME'] = $taskStatuses[$task['STATUS']];
        $task['PRIORITY_NAME'] = $priorityStatuses[$task['PRIORITY']];

        // вычислим значение поля Дней/Часов/Минут между датой CREATED_DATE и CLOSED_DATE(если оно null), то берем текущую дату
        if( $task['CLOSED_DATE'] != null ) {
            $differenceBetweenTwoDates = secondsToTime($task['CLOSED_DATE']->getTimestamp() - $task['CREATED_DATE']->getTimestamp());
        } else {
            $differenceBetweenTwoDates = secondsToTime( strtotime(date('Y-m-d H:m:s')) - $task['CREATED_DATE']->getTimestamp() );
        }

        // запишем переменную $differenceBetweenTwoDates, которая представляет объект с количеством дней, часов, минут и запишем в $task['D_H_M']
        $task['D_H_M'] = "";
        foreach( $differenceBetweenTwoDates as $key => $value ) {
            if($key === "d" || $key === "h" || $key === "m") {
                $task['D_H_M'] .= $value;
                if($key === 'd' || $key === 'h') {
                    $task['D_H_M'] .= "/";
                }
            }
        }

        // Формируем массив $rows[]['DATA'] который будет содержать записи
        $rows[]['data'] = $task;
    }


    // укажем поля фильтра, которые будут присутствовать в нашем фильтре над гридом
    $ui_filter = [
        ['name'=>'ID задачи', 'id'=>'ID', 'type'=>'text', 'default'=>true],
        ['name'=>'Ответственный', 'id'=>'RESPONSIBLE_FULL_NAME', 'type'=>'dest_selector', 'default'=>true],
        ['name'=>'Название', 'id'=>'TITLE', 'type'=>'text', 'default'=>true],
        ['name'=>'Статус', 'id'=>'STATUS', 'type'=>'text', 'default'=>true],
        ['name'=>'Важность', 'id'=>'PRIORITY', 'type'=>'text', 'default'=>true],
        ['name'=>'Дата создания', 'id'=>'CREATED_DATE', 'type'=>'date', 'default'=>true],
        ['name'=>'Дата начала', 'id'=>'DATE_START', 'type'=>'date', 'default'=>true],
        ['name'=>'Дата завершения', 'id'=>'CLOSED_DATE', 'type'=>'date', 'default'=>true],
        ['name'=>'Крайний срок', 'id'=>'DEADLINE', 'type'=>'date', 'default'=>true],
    ];

?>
<?php /** Вывод фильтра  */ ?>
<div class="container-fluid" style="background-color: lightblue;">
    <div class="row">
        <div class="col-sm-12">
            <div style="padding-top: 20px; margin-bottom: -10px;"><b>Фильтр</b></div>
            <?php
                $APPLICATION->IncludeComponent(
                    'bitrix:main.ui.filter',
                    '',
                    [
                        'FILTER_ID' => $report_list,
                        'GRID_ID' => $report_list,
                        'FILTER' => $ui_filter,
                        'ENABLE_LIVE_SEARCH' => true,
                        'ENABLE_LABEL' => true
                    ]
                );
            ?>
        </div>
    </div>
</div>
<style>
    .main-ui-filter-search {width: 50%;}
    .workarea-content {background-color: lightblue;}
</style>
<?php /** Вывод грида */ ?>
<div class="container-fluid" style="background-color: lightblue; padding-bottom: 15px;">
    <div class="row">
        <div class="col-sm-12">
            <div style="padding-top: 20px; margin-bottom: 15px;"><b>Отчет по задачам сотрудников</b></div>
            <?php
                $APPLICATION->IncludeComponent(
                    'bitrix:main.ui.grid',
                    '',
                    [
                        'GRID_ID' => 'report_list',
                        'HEADERS' => [
                            ['id'=>'ID',                                'name'=>'ID',                           'sort'=>'ID',                             'default'=>true],
                            ['id'=>'RESPONSIBLE_FULL_NAME',             'name'=>'Ответственный',                'sort'=>'RESPONSIBLE_FULL_NAME',          'default'=>true],
                            ['id'=>'TITLE',                             'name'=>'Название',                     'sort'=>'TITLE',                          'default'=>true],
                            ['id'=>'STATUS_NAME',                       'name'=>'Статус',                       'sort'=>'STATUS',                         'default'=>true],
                            ['id'=>'PRIORITY_NAME',                     'name'=>'Важность',                     'sort'=>'PRIORITY',                       'default'=>true],
                            ['id'=>'D_H_M',                             'name'=>'Дней/Часов/Минут',                                                       'default'=>true],
                            ['id'=>'CREATED_DATE',                      'name'=>'Дата создания',                'sort'=>'CREATED_DATE',                   'default'=>true],
                            ['id'=>'DATE_START',                        'name'=>'Дата начала',                  'sort'=>'DATE_START',                     'default'=>true],
                            ['id'=>'CLOSED_DATE',                       'name'=>'Дата завершения',              'sort'=>'CLOSED_DATE',                    'default'=>true],
                            ['id'=>'DEADLINE',                          'name'=>'Крайний срок',                 'sort'=>'DEADLINE',                       'default'=>true],
                        ],
                        'ROWS' => $rows,
                        'TOTAL_ROWS_COUNT' => $totalRowsCount,
                        'SHOW_ROW_CHECKBOXES' => false,
                        'NAV_OBJECT' => $nav,
                        'AJAX_MODE' => 'Y',
                        'AJAX_ID' => \CAjax::getComponentID('bitrix:main.ui.grid', '.default', ''),
                        'PAGE_SIZES' =>  [
                            ['NAME' => '5', 'VALUE' => '5'],
                            ['NAME' => '10', 'VALUE' => '10'],
                            ['NAME' => '20', 'VALUE' => '20'],
                            ['NAME' => '50', 'VALUE' => '50'],
                            ['NAME' => '100', 'VALUE' => '100']
                        ],
                        'AJAX_OPTION_JUMP'          => 'N',
                        'SHOW_CHECK_ALL_CHECKBOXES' => false,
                        'SHOW_ROW_ACTIONS_MENU'     => true,
                        'SHOW_GRID_SETTINGS_MENU'   => true,
                        'SHOW_NAVIGATION_PANEL'     => true,
                        'SHOW_PAGINATION'           => true,
                        'SHOW_SELECTED_COUNTER'     => true,
                        'SHOW_TOTAL_COUNTER'        => true,
                        'SHOW_PAGESIZE'             => true,
                        'SHOW_ACTION_PANEL'         => true,
                        'ALLOW_COLUMNS_SORT'        => true,
                        'ALLOW_COLUMNS_RESIZE'      => true,
                        'ALLOW_HORIZONTAL_SCROLL'   => true,
                        'ALLOW_SORT'                => true,
                        'ALLOW_PIN_HEADER'          => true,
                        'AJAX_OPTION_HISTORY'       => 'N'
                    ],

                );
            ?>
        </div>
    </div>
</div>
<?php
    function secondsToTime($inputSeconds) {

        $secondsInAMinute = 60;
        $secondsInAnHour  = 60 * $secondsInAMinute;
        $secondsInADay    = 24 * $secondsInAnHour;

        // extract days
        $days = floor($inputSeconds / $secondsInADay);

        // extract hours
        $hourSeconds = $inputSeconds % $secondsInADay;
        $hours = floor($hourSeconds / $secondsInAnHour);

        // extract minutes
        $minuteSeconds = $hourSeconds % $secondsInAnHour;
        $minutes = floor($minuteSeconds / $secondsInAMinute);

        // extract the remaining seconds
        $remainingSeconds = $minuteSeconds % $secondsInAMinute;
        $seconds = ceil($remainingSeconds);

        // return the final array
        $obj = array(
            'd' => (int) $days,
            'h' => (int) $hours,
            'm' => (int) $minutes,
            's' => (int) $seconds,
        );
        return $obj;
    }
?>
<?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');