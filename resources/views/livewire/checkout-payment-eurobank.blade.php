<div>
    <div x-data="{ submitted: false }" x-init="!submitted && $refs.form.submit(); submitted = true">
        <div class="my-6 xl:my-8 text-center">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.redirectingToPayment') }}
            </div>
            <div class="text-gray-700">
                {{ trans('statamic-resrv::frontend.pleaseWait') }}
            </div>
        </div>
        <form x-ref="form" method="POST" action="{{ json_decode($clientSecret)->redirectUrl }}">
            @foreach(json_decode($clientSecret)->postData as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
        </form>
    </div>
</div>
