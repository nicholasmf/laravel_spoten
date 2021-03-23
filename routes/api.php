<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

function createOrderSumQuery(Datetime $day, string $company_id) {

    $data = DB::table('orders')
                ->select(DB::raw("SUM(orders.value) as value"), DB::raw("COUNT(orders.id) as count"), DB::raw("UNIX_TIMESTAMP(DATE(orders.created_at)) as label"))
                ->where('company_id', '=', $company_id)
                ->whereDate('created_at', '=', $day->format('Y-m-d'))
                ->groupBy('label')
                ->get();

    if ($data->count() == 0) {
        $data->push((object) [
            "value" => 0,
            "count" => 0,
            "label" => $day->format('Y-m-d')
        ]);
    }

    return $data;
}

Route::get('/day-report', function(Request $request) {
    if (!$request->has("company_id")) {
        return 'no-company-id';
    }

    $today = new DateTime('2021-01-12');
    $yesterday = (clone $today)->sub(new DateInterval('P1D'));

    $company_id = $request->input("company_id");
    $query = createOrderSumQuery($today, $company_id);
    $previousPeriodQuery = createOrderSumQuery($yesterday, $company_id);

    return json_encode((object) [
        "today" => $query,
        "yesterday" => $previousPeriodQuery
    ]);
});

function getLabelByTimeframe(string $timeframe){
    switch($timeframe) {
        case 'day': {
            $label = 'UNIX_TIMESTAMP(DATE(created_at))';
        } break;
        case 'week': {
            $label = 'UNIX_TIMESTAMP(SUBDATE(DATE(created_at), DAYOFWEEK(created_at) - 1))';
        } break;
        case 'month': {
            $label = 'UNIX_TIMESTAMP(SUBDATE(DATE(created_at), DAYOFMONTH(created_at) - 1))';
        } break;
        case 'week-weekend': {
            $label = 'IF (DAYOFWEEK(created_at) = 1 OR DAYOFWEEK(created_at) = 7, \'weekend\', \'weekday\')';
        } break;
        case 'am-pm': {
            $label = 'TIME_FORMAT(orders.created_at, \'%p\')';
        } break;
        default: {
            $label = 'UNIX_TIMESTAMP(DATE(created_at))';
        }
    }

    return $label;
}


function createOrderString(DateTime $start, DateTime $end, string $company_id, string $metric, $unit) {
    $label = '';
    $value = '';

    switch($unit) {
        case 'hour': {
            $label = 'FLOOR(UNIX_TIMESTAMP(DATE_FORMAT(orders.created_at, "%Y-%m-%d %H:00:00")))';
        } break;
        case 'day': {
            $label = 'UNIX_TIMESTAMP(DATE(created_at))';
        } break;
        case 'week': {
            $label = 'UNIX_TIMESTAMP(SUBDATE(DATE(created_at), DAYOFWEEK(created_at) - 1))';
        } break;
        case 'month': {
            $label = 'UNIX_TIMESTAMP(SUBDATE(DATE(created_at), DAYOFMONTH(created_at) - 1))';
        } break;
        case 'year': {
            $label = 'UNIX_TIMESTAMP(SUBDATE(DATE(created_at), DAYOFWEEK(created_at) - 1))';
        } break;
        case 'week-weekend': {
            $label = 'IF (DAYOFWEEK(created_at) = 1 OR DAYOFWEEK(created_at) = 7, \'weekend\', \'weekday\')';
        } break;
        case 'am-pm': {
            $label = 'TIME_FORMAT(orders.created_at, \'%p\')';
        } break;
        default: {
            $label = 'UNIX_TIMESTAMP(DATE(created_at))';
        }
    }

    switch($metric) {
        case 'n-orders': {
            $value = 'IFNULL(COUNT(orders.id), 0)';
        } break;
        case 'rs-orders': {
            $value = 'IFNULL(SUM(orders.value), 0.0)';
        } break;
        case 'n-orders-cumulative': {
            $value = "SUM(IFNULL(COUNT(orders.id), 0)) OVER(order by {$label})";
        } break;
        case 'rs-orders-cumulative': {
            $value = "SUM(IFNULL(SUM(orders.value), 0.0)) OVER(order by {$label})";
        } break;
        default: {
            $value = 'IFNULL(COUNT(orders.id), 0)';
        }
    }

    $data = DB::table('orders')
        ->select(DB::raw("{$label} as label"), DB::raw("{$value} as value"))
        ->where('company_id', '=', $company_id)
        ->whereRaw("DATE(created_at) BETWEEN '{$start->format('Y-m-d')}' AND '{$end->format('Y-m-d')}'")
        ->groupBy('label')
        ->orderBy('label')
        ->get();

    // TODO - fill in empty dates
    // $period = new DatePeriod($start, new DateInterval("P1D"), $end);
    // foreach($period as $date) {
    //     if (!$data->contains('label', $date->format('Y-m-d'))) {
    //         $data->push((object) [
    //             "label" => $date->format('Y-m-d'),
    //             "value" => 0
    //         ]);
    //     }
    // }
    // return $data->sortBy('label')->values();

    return $data;
}

function calcStartEndByTimeframe($timeframe) {
    $start = new DateTime();
    $end = new DateTime();

    switch ($timeframe) {
        case 'day': {
            $start = new DateTime('today');
        } break;
        case 'yesterday': {
            $start = new DateTime('yesterday');
            $end = new DateTime('today');
            $end = $end->sub(new DateInterval("PT1S"));
        } break;
        case 'week': {
            $start = $start->sub(new DateInterval("P7D"));
        } break;
        case 'month': {
            $start = $start->sub(new DateInterval("P1M"));
        } break;
        case 'wtd': {
            $start = new DateTime('Sunday this week');
        } break;
        case 'mtd': {
            $start = new DateTime('first day of this month');
        } break;
        case 'ytd': {
            $start = new DateTime('first day of January');
        } break;
        default: {
            throw new Error("invalid timeframe");
        }
    }
    
    return [
        "start" => $start,
        "end" => $end,
    ];
}

function calcUnitByDiff($diff) {
    if ($diff < 14) {
        return "day";
    } else if ($diff >= 365) {
        return "year";
    } else if ($diff >= 30) {
        return "month";
    } else if ($diff >= 14) {
        return "week";
    }
}

Route::get('/testing', function(Request $request) {
    $timeframe = $request->input("timeframe");

    $startEnd = calcStartEndByTimeframe($timeframe);
    $start = $startEnd["start"];
    $end = $startEnd["end"];

    $previousPeriodStart = (clone $start)->sub(new DateInterval('P1W'));
    $previousPeriodEnd = (clone $end)->sub(new DateInterval('P1W'));

    $diff = $end->diff($start)->days + 1;
    $unit = calcUnitByDiff($diff);

    $sunday = new DateTime("Sunday this week");

    return json_encode((object) [
        "start" => $start->format('Y-m-d H-i-s'),
        "end" => $end->format('Y-m-d H-i-s'),
        "prevStart" => $previousPeriodStart,
        "prevEnd" => $previousPeriodEnd,
        "diff" => $diff,
        "unit" => $unit,
        "sunday" => $sunday,
    ]);
});

Route::get('/n-orders', function(Request $request) {
    if (!$request->has("company_id")) {
        return 'no-company-id';
    }
    
    $metric = $request->input("metric");
    $timeframe = $request->input("timeframe");
    $company_id = $request->input("company_id");
    $filterStart = $request->input("filterStart");
    $filterEnd = $request->input("filterEnd");

    if ($request->has("timeframe")) {
        $startEnd = calcStartEndByTimeframe($timeframe);
        $start = $startEnd["start"];
        $end = $startEnd["end"];
    } else {
        if ($request->has("filterStart")) {
            $start = new DateTime($filterStart);
        } else {
            $start = new DateTime('today');
        }

        if ($request->has("filterEnd")) {
            $end = new DateTime($filterEnd);
        } else {
            $end = new DateTime();
        }
    }

    $diff = $end->diff($start)->days + 1;
    $previousPeriodStart = (clone $start)->sub(new DateInterval("P".($diff)."D"));
    $previousPeriodEnd = (clone $start)->sub(new DateInterval('P1D'));

    if (!$request->has("unit")) {
        $unit = calcUnitByDiff($diff);
    } else {
        $unit = $request->input("unit");
    }

    if ($timeframe == 'wtd') {
        $previousPeriodStart = (clone $start)->sub(new DateInterval('P1W'));
        $previousPeriodEnd = (clone $end)->sub(new DateInterval('P1W'));

        $unit = 'day';
    } else if ($timeframe == 'mtd') {
        $previousPeriodStart = (clone $start)->sub(new DateInterval('P1M'));
        $previousPeriodEnd = (clone $end)->sub(new DateInterval('P1M'));

        $unit = 'day';
    } else if ($timeframe === 'ytd') {    
        $previousPeriodStart = (clone $start)->sub(new DateInterval('P1Y'));
        $previousPeriodEnd = (clone $end)->sub(new DateInterval('P1Y'));

        $unit = 'week';
    }

    $query = createOrderString($start, $end, $company_id, $metric, $unit);
    $previousPeriodQuery = createOrderString($previousPeriodStart, $previousPeriodEnd, $company_id, $metric, $unit);

    $previousPeriodQuery->each(function ($item, $key) use ($query) {
        if (isset($query[$key])) {
            $item->label = $query[$key]->label;
        }
    });

    return json_encode((object) [
        "period" => $query,
        "previousPeriod" => $previousPeriodQuery
    ]);
});

function createNewCustomersQuery(Datetime $start, Datetime $end, string $company_id, string $timeframe) {
    $data = DB::table('vouchers')
        ->select('vouchers.user_id', DB::raw("MIN(orders.created_at) as firstOrder"))
        ->join('orders', function ($join) use ($company_id) {
            $join->on('orders.voucher_id', '=', 'vouchers.id')
                ->where('orders.company_id', '=', $company_id);
        })
        ->groupBy('vouchers.user_id')
        ->havingBetween('firstOrder', [$start->format('Y-m-d'), $end->format('Y-m-d')])
        ->get();

    return $data;
}

Route::get('/new-customers', function(Request $request) {
    $timeframe = $request->input("timeframe");
    $company_id = $request->input("company_id");
    $filterStart = $request->input("filterStart");
    $filterEnd = $request->input("filterEnd");

    $start = $filterStart ? new DateTime($filterStart) : new DateTime('2021-01-10');
    $end = $filterEnd ? new DateTime($filterEnd) : new DateTime('2021-01-16');
    $diff = $end->diff($start);
    $previousPeriodStart = (clone $start)->sub(new DateInterval("P".($diff->d + 1)."D"));
    $previousPeriodEnd = (clone $start)->sub(new DateInterval('P1D'));

    $query = createNewCustomersQuery($start, $end, $company_id, $timeframe);

    return json_encode((object) [
        "period" => $query,
    ]);
});

function createChurnQuery(DateTime $start, DateTime $end, string $company_id, string $metric, string $timeframe) {

}

Route::get('/churn', function(Request $request) {
    if (!$request->has("company_id")) {
        return 'no-company-id';
    }
    
    $metric = $request->input("metric");
    $timeframe = $request->input("timeframe");
    $company_id = $request->input("company_id");
    $filterStart = $request->input("filterStart");
    $filterEnd = $request->input("filterEnd");

    $start = $filterStart ? new DateTime($filterStart) : new DateTime('2021-01-10');
    $end = $filterEnd ? new DateTime($filterEnd) : new DateTime('2021-01-16');
    $diff = $end->diff($start);
    $previousPeriodStart = (clone $start)->sub(new DateInterval("P".($diff->d + 1)."D"));
    $previousPeriodEnd = (clone $start)->sub(new DateInterval('P1D'));

    // TODO check month limits (first day / last day)
    if ($timeframe == 'mtd') {
        $start = new DateTime('first day of this month');
        $end = new DateTime();

        $previousPeriodStart = (clone $start)->sub(new DateInterval('P1M'));
        $previousPeriodEnd = (clone $end)->sub(new DateInterval('P1M'));

        $timeframe = 'day';
    // TODO review ytd
    } else if ($timeframe === 'ytd') {
        $start = new DateTime('first day of this year');
        $end = new DateTime();

        $previousPeriodStart = (clone $start)->sub(new DateInterval('P1Y'));
        $previousPeriodEnd = (clone $end)->sub(new DateInterval('P1Y'));

        $timeframe = 'week';
    }

    $query = createOrderString($start, $end, $company_id, $metric, $timeframe);
    $previousPeriodQuery = createOrderString($previousPeriodStart, $previousPeriodEnd, $company_id, $metric, $timeframe);

    $previousPeriodQuery->each(function ($item, $key) use ($query) {
        if (isset($query[$key])) {
            $item->label = $query[$key]->label;
        }
    });

    return json_encode((object) [
        "period" => $query,
        "previousPeriod" => $previousPeriodQuery
    ]);
});