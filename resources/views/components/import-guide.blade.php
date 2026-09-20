@props(['spec', 'specKey', 'open' => false])

{{--
    Column-naming guide for a file import. Rendered from config/imports.php —
    the same registry the parser matches against and the template is built
    from — so the guide, the matching and the template can never drift apart.

    Ported from server/templates/dashboard/_import_guide.php.
--}}

@if ($spec)
    <details class="panel import-guide" {{ $open ? 'open' : '' }}>
        <summary><b>Column headers &amp; naming rules</b> — what this file needs to contain</summary>

        <p class="muted">{{ $spec['intro'] }}</p>

        <ul class="plain small">
            <li><b>Accepted formats:</b> {{ $spec['formats'] }}</li>
            <li><b>Header names are matched loosely.</b> Case, spaces, underscores, hyphens and
                punctuation are all ignored — <code>Wise Recipient ID</code>, <code>wise_recipient_id</code>
                and <code>WISE-RECIPIENT-ID</code> are treated as the same column.</li>
            <li><b>Column order does not matter</b>, and extra columns you don't need are ignored.</li>
            <li><b>The header row does not have to be row&nbsp;1</b> — blank rows, titles and merged
                banners above the table are skipped automatically.</li>
            <li><b>Matching:</b> {{ $spec['match'] }}</li>
        </ul>

        <table class="data" data-nofilter>
            <thead>
                <tr><th>Column header</th><th>Also accepted</th><th>Required</th><th>Example</th><th>What it does</th></tr>
            </thead>
            <tbody>
                @foreach ($spec['columns'] as $column)
                    <tr>
                        <td><code>{{ $column['name'] }}</code></td>
                        <td class="small muted">{{ $column['aliases'] ? implode(', ', $column['aliases']) : '—' }}</td>
                        <td>
                            @if ($column['required'])
                                <span class="status rejected">required</span>
                            @else
                                <span class="tag">optional</span>
                            @endif
                        </td>
                        <td class="small">{{ $column['example'] ?? '' }}</td>
                        <td class="small muted">{{ $column['desc'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="muted small">Not sure about the layout?
            <a href="{{ url('/app/import/template/' . $specKey) }}">Download a blank template</a> —
            it carries these exact headers.</p>
    </details>
@endif
