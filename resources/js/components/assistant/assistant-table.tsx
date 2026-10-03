import {
    formatCell,
    type TableColumn,
    type TableData,
    type ViewData,
} from '@agentic-actions/client';
import { ActionTable } from '@agentic-actions/client/views';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';

/**
 * A table the assistant showed: the chart its rows' shape calls for, drawn here with shadcn/ui's chart, then the
 * package's unstyled table with its time and Refresh. The rows come from the server as the query returned them; the
 * model only describes them. A refresh redraws the chart from the same fresh rows.
 */
export default function AssistantTable({
    view,
    team,
}: {
    view: ViewData & { id: string };
    team: string;
}) {
    const [table, setTable] = useState<TableData>(view.table);

    return (
        <div className="flex flex-col gap-2 rounded-lg border bg-card p-3 [&_[data-agentic-view]]:flex [&_[data-agentic-view]]:flex-col [&_[data-agentic-view]]:gap-1.5 [&_time]:text-[11px] [&_time]:text-muted-foreground">
            <TableChart table={table} />
            <div className="max-h-72 overflow-y-auto">
                <ActionTable
                    view={view}
                    refreshUrl={`/${team}/actions/_views`}
                    onRefresh={(fresh) => setTable(fresh.table)}
                    classNames={{
                        table: 'w-full border-collapse text-xs',
                        caption: 'pb-1 text-start text-muted-foreground',
                        head: 'border-b px-1.5 py-1 text-start font-medium text-muted-foreground',
                        cell: 'border-b px-1.5 py-1',
                        number: 'border-b px-1.5 py-1 tabular-nums',
                        note: 'text-[11px] text-muted-foreground',
                        refresh:
                            'self-start rounded-md border px-2 py-0.5 text-xs hover:bg-accent disabled:pointer-events-none disabled:opacity-50',
                    }}
                />
            </div>
        </div>
    );
}

/** The chart of table.chart: bars or a line over its x column, or the numbers of a single row; none otherwise. */
function TableChart({ table }: { table: TableData }) {
    const { type, x, y } = table.chart;
    const column = (key: string | undefined): TableColumn | undefined =>
        table.columns.find((candidate) => candidate.key === key);
    const values = y.flatMap((key) => {
        const found = column(key);

        return found === undefined ? [] : [found];
    });

    if (type === 'none' || values.length === 0) {
        return null;
    }

    if (type === 'metric') {
        const row = table.rows[0] ?? {};

        return (
            <div className="grid grid-cols-2 gap-2">
                {values.map((value) => (
                    <div
                        key={value.key}
                        className="rounded-md bg-muted px-3 py-2"
                    >
                        <div className="text-xs text-muted-foreground">
                            {value.label}
                        </div>
                        <div className="text-lg font-semibold tabular-nums">
                            {formatCell(row[value.key], value)}
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    const axis = column(x);
    const config: ChartConfig = Object.fromEntries(
        values.map((value, index) => [
            value.key,
            { label: value.label, color: `var(--chart-${(index % 5) + 1})` },
        ]),
    );
    const Chart = type === 'line' ? LineChart : BarChart;
    // A row with no value on the axis, such as tasks with no project, keeps its bar, under a dash.
    const rows = table.rows.map((row) =>
        x !== undefined && row[x] == null ? { ...row, [x]: '—' } : row,
    );

    return (
        <ChartContainer config={config} className="aspect-auto h-44 w-full">
            <Chart data={rows} accessibilityLayer>
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey={x}
                    tickLine={false}
                    axisLine={false}
                    tickFormatter={(tick: unknown) => axisTick(tick, axis)}
                />
                <YAxis
                    width={32}
                    tickLine={false}
                    axisLine={false}
                    allowDecimals={values[0].type !== 'integer'}
                    tickFormatter={(tick: unknown) =>
                        formatCell(tick, values[0])
                    }
                />
                <ChartTooltip content={<ChartTooltipContent />} />
                {values.map((value) =>
                    type === 'line' ? (
                        <Line
                            key={value.key}
                            dataKey={value.key}
                            type="monotone"
                            stroke={`var(--color-${value.key})`}
                            strokeWidth={2}
                            dot={false}
                        />
                    ) : (
                        <Bar
                            key={value.key}
                            dataKey={value.key}
                            fill={`var(--color-${value.key})`}
                            radius={4}
                        />
                    ),
                )}
            </Chart>
        </ChartContainer>
    );
}

/** A short tick for the x axis: a date as "Sep 20", anything else as its cell. */
function axisTick(tick: unknown, axis: TableColumn | undefined): string {
    if (axis?.type === 'date' && typeof tick === 'string') {
        const day = new Date(tick);

        return Number.isNaN(day.getTime())
            ? tick
            : new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
                  month: 'short',
                  day: 'numeric',
                  timeZone: 'UTC',
              }).format(day);
    }

    return axis === undefined ? String(tick) : formatCell(tick, axis);
}
