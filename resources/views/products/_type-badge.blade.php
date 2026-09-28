<span class="badge {{ $product->type === \App\Enums\ProductType::Service ? 'text-bg-info' : 'text-bg-primary' }}">{{ $product->type->label() }}</span>
