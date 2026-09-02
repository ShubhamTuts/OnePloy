{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ config('app.name') ?? 'OnePloy' }}

{{ Illuminate\Mail\Markdown::parse('[Contact Support](https://github.com/ShubhamTuts/OnePloy/issues)') }}
