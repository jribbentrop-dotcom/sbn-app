import { NylonSampler } from './NylonSampler.js';

let instance: NylonSampler | null = null;

export function getSharedNylon(): NylonSampler {
    if (!instance) instance = new NylonSampler();
    return instance;
}
