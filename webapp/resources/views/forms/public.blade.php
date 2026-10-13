<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:16px;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;background:transparent}
        form{display:grid;gap:14px;grid-template-columns:repeat({{ (int) ($form->schema['columns'] ?? 1) }},minmax(0,1fr));max-width:720px}
        .full,.group,.actions,.msg{grid-column:1/-1}.group{font-weight:600;margin-top:8px;border-bottom:1px solid #e2e8f0;padding-bottom:4px}
        label{display:block;font-size:13px;font-weight:600;margin-bottom:4px}input,select,textarea{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;background:#fff}
        input:focus,select:focus,textarea:focus{outline:2px solid #6366f155;border-color:#6366f1}.req{color:#dc2626}.hint{font-size:12px;color:#64748b;margin-top:2px}
        button{background:#4f46e5;color:#fff;border:0;border-radius:8px;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer}button:disabled{opacity:.5}
        .err{color:#dc2626;font-size:12px;margin-top:2px}.msg{padding:12px 14px;border-radius:8px;background:#ecfdf5;color:#065f46}.hp{position:absolute;left:-9999px}
    </style>
</head>
<body>
@php($fields = collect($form->schema['fields'] ?? []))
<form id="f" novalidate>
    <input class="hp" type="text" name="_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    @foreach($fields->groupBy(fn ($f) => $f['group'] ?? '') as $group => $items)
        @if($group !== '')<div class="group">{{ $group }}</div>@endif
        @foreach($items as $f)
            <div class="{{ in_array($f['type'], ['textarea']) ? 'full' : '' }}">
                @if(($f['type'] ?? 'text') === 'checkbox')
                    <label><input type="checkbox" name="{{ $f['key'] }}" value="1" style="width:auto"> {{ $f['label'] }} @if($f['required'] ?? false)<span class="req">*</span>@endif</label>
                @else
                    <label for="{{ $f['key'] }}">{{ $f['label'] }} @if($f['required'] ?? false)<span class="req">*</span>@endif</label>
                    @if($f['type'] === 'textarea')
                        <textarea id="{{ $f['key'] }}" name="{{ $f['key'] }}" rows="4" placeholder="{{ $f['placeholder'] ?? '' }}"></textarea>
                    @elseif($f['type'] === 'select')
                        <select id="{{ $f['key'] }}" name="{{ $f['key'] }}"><option value=""></option>@foreach(($f['options'] ?? []) as $o)<option>{{ $o }}</option>@endforeach</select>
                    @else
                        <input id="{{ $f['key'] }}" name="{{ $f['key'] }}" type="{{ ['email'=>'email','number'=>'number','date'=>'date','url'=>'url','phone'=>'tel'][$f['type']] ?? 'text' }}" placeholder="{{ $f['placeholder'] ?? '' }}">
                    @endif
                @endif
                @if(!empty($f['tooltip']))<div class="hint">{{ $f['tooltip'] }}</div>@endif
                <div class="err" data-err="{{ $f['key'] }}"></div>
            </div>
        @endforeach
    @endforeach
    <div class="actions"><button type="submit">{{ $form->settings['submit_label'] ?? 'Submit' }}</button></div>
    <div class="msg" id="done" hidden></div>
</form>
<script>
const form=document.getElementById('f'),done=document.getElementById('done');
const resize=()=>parent.postMessage({orvynFormHeight:document.documentElement.scrollHeight},'*');
new ResizeObserver(resize).observe(document.body);resize();
form.addEventListener('submit',async e=>{
  e.preventDefault();document.querySelectorAll('[data-err]').forEach(x=>x.textContent='');
  const btn=form.querySelector('button');btn.disabled=true;
  const data={};new FormData(form).forEach((v,k)=>data[k]=v);
  form.querySelectorAll('input[type=checkbox]').forEach(c=>data[c.name]=c.checked?1:0);
  try{
    const r=await fetch('{{ url("/api/v1/forms/{$form->uuid}/submit") }}',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(data)});
    const j=await r.json();
    if(r.status===422){Object.entries(j.errors||{}).forEach(([k,v])=>{const el=document.querySelector('[data-err="'+k+'"]');if(el)el.textContent=v[0]});btn.disabled=false;return}
    if(!r.ok)throw 0;
    if(j.redirect_url){(window.top||window).location.href=j.redirect_url;return}
    done.textContent=j.thank_you||'Thank you!';done.hidden=false;form.querySelectorAll('div:not(.msg),button').forEach(x=>x.hidden=true);
  }catch(_){done.textContent='Sorry, something went wrong. Please try again.';done.hidden=false;done.style.background='#fef2f2';done.style.color='#991b1b';btn.disabled=false}
  resize();
});
</script>
</body>
</html>
